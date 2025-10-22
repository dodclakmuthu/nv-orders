<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RollbackOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The Order primary key to roll back.
     */
    public int $orderId;

    /**
     * Ensure one rollback per order.
     */
    public function uniqueId(): string
    {
        return 'rollback-order-' . $this->orderId;
    }

    /**
     * Keep the unique lock for a few minutes.
     */
    public int $uniqueFor = 300; // seconds

    /**
     * Retry/backoff for transient errors.
     */
    public int $tries = 3;

    public function backoff(): int
    {
        return 5;
    }

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
        // $this->onQueue('payments'); // optional: dedicate a queue for payment-related jobs
    }

    /**
     * Prevent overlapping handling across workers.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->dontRelease(),
        ];
    }

    public function handle(InventoryService $inventory): void
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->with(['items.product', 'customer'])
            ->find($this->orderId);

        if (!$order) {
            throw new ModelNotFoundException("RollbackOrderJob: Order {$this->orderId} not found.");
        }

        // Idempotency/guard rails
        if (in_array($order->status, ['finalized'], true)) {
            Log::warning("RollbackOrderJob: Order {$order->id} is {$order->status}; cannot roll back. Skipping.");
            return;
        }
        if (in_array($order->status, ['rolled_back', 'failed'], true)) {
            Log::info("RollbackOrderJob: Order {$order->id} already {$order->status}; skipping.");
            return;
        }

        // If we previously reserved inventory, release it safely.
        try {
            $inventory->release($order);
        } catch (\Throwable $e) {
            // Release is idempotent (will subtract what was reserved); log and continue to mark order rolled_back.
            Log::error("RollbackOrderJob: Failed releasing inventory for Order {$order->id}: {$e->getMessage()}");
        }

        // Mark order as rolled back
        $order->update(['status' => 'rolled_back']);

        // Send failure notification (queued, non-blocking)
        try {
            SendOrderNotificationJob::dispatch($order->id, 'failure');
        } catch (\Throwable $e) {
            Log::error("RollbackOrderJob: Notification dispatch failed for Order {$order->id}: {$e->getMessage()}");
        }

        Log::info("RollbackOrderJob: Order {$order->id} rolled back successfully.");
    }
}
