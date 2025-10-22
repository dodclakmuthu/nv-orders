<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InventoryService;
use App\Services\KpiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The Order primary key to finalize.
     */
    public int $orderId;

    /**
     * Ensure one finalize per order.
     */
    public function uniqueId(): string
    {
        return 'finalize-order-' . $this->orderId;
    }

    /**
     * Keep the unique lock a few minutes to avoid duplicates.
     */
    public int $uniqueFor = 300; // seconds

    /**
     * Retry policy.
     */
    public int $tries = 3;

    public function backoff(): int
    {
        return 5;
    }

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Extra guard to prevent parallel handling.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->dontRelease(),
        ];
    }

    public function handle(InventoryService $inventory, KpiService $kpis): void
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->with(['items.product', 'customer'])
            ->find($this->orderId);

        if (!$order) {
            throw new ModelNotFoundException("FinalizeOrderJob: Order {$this->orderId} not found.");
        }

        // Idempotency: if previously finalized, do nothing.
        if ($order->status === 'finalized') {
            Log::info("FinalizeOrderJob: Order {$order->id} already finalized; skipping.");
            return;
        }

        // Only finalize orders that are currently reserved or paid-successful in your domain
        if (!in_array($order->status, ['reserved', 'paid'], true)) {
            Log::warning("FinalizeOrderJob: Order {$order->id} has status {$order->status}; not eligible to finalize. Skipping.");
            return;
        }

        // 1) Commit inventory (reserved -> sold, stock-- per item, sets status=finalized)
        $inventory->commit($order);
        $order->refresh(); // ensure we see updated status/amounts

        // 2) Update KPIs and leaderboard
        try {
            $kpis->incrForOrderFinalized($order);
        } catch (\Throwable $e) {
            // Do not fail the job after inventory commit; just log. KPIs can be reconciled later if needed.
            Log::error("FinalizeOrderJob: KPI update failed for Order {$order->id}: {$e->getMessage()}");
        }

        // 3) Notify (queued, non-blocking)
        try {
            SendOrderNotificationJob::dispatch($order->id, 'success');
        } catch (\Throwable $e) {
            Log::error("FinalizeOrderJob: Notification dispatch failed for Order {$order->id}: {$e->getMessage()}");
        }

        Log::info("FinalizeOrderJob: Order {$order->id} finalized successfully.");
    }
}
