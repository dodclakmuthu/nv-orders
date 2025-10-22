<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var array<int, array<string, string|int|float|null>>
     * Each element is a CSV row for the same order_number.
     * Expected keys per row:
     *   order_number, order_date (Y-m-d), customer_email, customer_name, sku, qty, price
     */
    public array $lines;

    /**
     * @var string|null UUID string set by the console command for this import
     */
    public ?string $importBatch;

    /**
     * @param array $lines       All CSV rows that belong to one order_number
     * @param string|null $importBatch
     */
    public function __construct(array $lines, ?string $importBatch = null)
    {
        $this->lines = $lines;
        $this->importBatch = $importBatch;
        // Optional: prioritize imports lower than workflow jobs
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if (empty($this->lines)) {
            return;
        }

        // Basic normalization (first row drives order meta)
        $first = $this->lines[0];

        $orderNumber   = (string) Arr::get($first, 'order_number', '');
        $orderDateRaw  = (string) Arr::get($first, 'order_date', '');
        $customerEmail = trim((string) Arr::get($first, 'customer_email', ''));
        $customerName  = trim((string) Arr::get($first, 'customer_name', ''));

        if ($orderNumber === '' || $orderDateRaw === '' || $customerEmail === '') {
            // Silently drop or log; here we throw to surface bad inputs
            throw new \InvalidArgumentException('Missing order_number, order_date, or customer_email in CSV group.');
        }

        $orderDate = CarbonImmutable::parse($orderDateRaw)->toDateString();
        $batch     = $this->importBatch ?: (string) Str::uuid();

        // Wrap everything to maintain consistency
        $orderId = DB::transaction(function () use (
            $orderNumber,
            $orderDate,
            $customerEmail,
            $customerName,
            $batch
        ) {
            // 1) Upsert customer
            /** @var Customer $customer */
            $customer = Customer::query()->firstOrCreate(
                ['email' => $customerEmail],
                ['name'  => $customerName !== '' ? $customerName : Str::before($customerEmail, '@')]
            );

            // 2) Create or get the Order (idempotent by order_number + import_batch)
            /** @var Order $order */
            $order = Order::query()->firstOrCreate(
                [
                    'order_number' => $orderNumber,
                    'import_batch' => $batch,
                ],
                [
                    'customer_id' => $customer->id,
                    'status'      => 'pending',
                    'order_date'  => $orderDate,
                    'subtotal'    => 0,
                    'total'       => 0,
                ]
            );

            // If order exists but belongs to a different customer/date, keep original;
            // otherwise, update missing fields (e.g., customer rename)
            if ($order->wasRecentlyCreated === false) {
                if ($order->customer_id !== $customer->id) {
                    $order->customer_id = $customer->id;
                }
                if ($order->order_date->toDateString() !== $orderDate) {
                    $order->order_date = $orderDate;
                }
                $order->save();
            }

            // 3) Upsert items and compute totals
            $subtotal = 0.0;

            foreach ($this->lines as $row) {
                $sku   = trim((string) Arr::get($row, 'sku', ''));
                $qty   = (int)   max(1, (int) Arr::get($row, 'qty', 0));
                $price = (float) Arr::get($row, 'price', 0.0);

                if ($sku === '' || $qty <= 0 || $price < 0) {
                    // Skip invalid lines; alternatively throw
                    continue;
                }

                // Ensure product exists (simple catalog)
                /** @var Product $product */
                $product = Product::query()->firstOrCreate(
                    ['sku' => $sku],
                    [
                        'name'   => $sku,
                        'stock'  => 0,          
                        'price'  => $price,     
                        'reserved' => 0,
                        'sold'     => 0,
                    ]
                );

                $lineTotal = round($qty * $price, 2);
                $subtotal += $lineTotal;

                // Avoid duplicate order_items for same product
                /** @var OrderItem $item */
                $item = OrderItem::query()->firstOrNew([
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                ]);

                // If existed, we *merge* qty and recompute line_total conservatively
                $item->qty        = (int) ($item->exists ? ($item->qty + $qty) : $qty);
                $item->unit_price = $price;
                $item->line_total = round($item->qty * $item->unit_price, 2);
                $item->save();
            }

            // 4) Finalize order money
            $order->subtotal = round($subtotal, 2);
            $order->total    = $order->subtotal; // taxes/ship/discounts could be applied here
            $order->save();

            return $order->id;
        }, 3);

        // 5) Kick off workflow: reserve stock → payment → finalize/rollback
        //    We dispatch a *single* job to start the chain.
        ReserveStockJob::dispatch($orderId)->onQueue('default');
    }
}
