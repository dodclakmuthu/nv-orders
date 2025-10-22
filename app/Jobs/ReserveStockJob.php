<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InventoryService;
use App\Services\PaymentGatewayFake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReserveStockJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The Order primary key to process.
     */
    public int $orderId;

    /**
     * Run only one job per order at a time.
     * Keep the unique lock for a short period (seconds).
     */
    public int $uniqueFor = 300; // 5 minutes

    /**
     * Number of tries before failing the job.
     */
    public int $tries = 3;

    /**
     * Backoff (seconds) between retries.
     */
    public function backoff(): int
    {
        return 5;
    }

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        $this->onQueue('default');
    }

    /**
     * Provide a unique id for ShouldBeUnique.
     */
    public function uniqueId(): string
    {
        return 'reserve-order-' . $this->orderId;
    }

    /**
     * Extra safety to prevent parallel handling in some queue drivers.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->dontRelease(),
        ];
    }

    public function handle(InventoryService $inventory, PaymentGatewayFake $payments): void
    {
        /** @var Order $order */
        $order = Order::query()
            ->with(['items.product', 'customer'])
            ->find($this->orderId);

        if (!$order) {
            throw new ModelNotFoundException("Order {$this->orderId} not found.");
        }

        // Only proceed for fresh imports
        if (!in_array($order->status, ['pending'], true)) {
            // Nothing to do; idempotent exit
            Log::info("ReserveStockJob: Order {$order->id} status={$order->status} not reservable, skipping.");
            return;
        }

        // Attempt to reserve inventory; service uses DB transactions + SELECT ... FOR UPDATE per product
        $reserved = $inventory->reserve($order);

        if (!$reserved) {
            // Could not reserve due to insufficient stock
            $order->update(['status' => 'failed']);
            Log::warning("ReserveStockJob: Insufficient stock for Order {$order->id}; marked failed.");
            return;
        }

        // Reservation succeeded, now initiate (async) payment "callback" simulation.
        try {
            $payments->initiate($order); // queues SimulatePaymentJob internally
            // We do not change status here; InventoryService already set 'reserved'.
            Log::info("ReserveStockJob: Order {$order->id} reserved. Payment initiated.");
        } catch (\Throwable $e) {
            // If anything blows up after reservation, release the reservation and mark rolled_back
            try {
                $inventory->release($order);
            } catch (\Throwable $releaseEx) {
                Log::error("ReserveStockJob: Failed to release reservation for Order {$order->id} after payment error: {$releaseEx->getMessage()}");
            }

            $order->update(['status' => 'rolled_back']);
            Log::error("ReserveStockJob: Payment initiation failed for Order {$order->id}. Rolled back. Error: {$e->getMessage()}");
            throw $e; // allow retry if transient
        }
    }
}
