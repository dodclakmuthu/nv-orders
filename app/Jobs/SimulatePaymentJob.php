<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimulatePaymentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Order and Payment identifiers.
     */
    public int $orderId;
    public int $paymentId;

    /**
     * Ensure only one simulation runs per payment.
     */
    public function uniqueId(): string
    {
        return 'simulate-payment-' . $this->paymentId;
    }

    /**
     * Keep the unique lock a few minutes to avoid duplicates.
     */
    public int $uniqueFor = 300; // seconds

    /**
     * Retries and backoff for transient errors (e.g., DB).
     */
    public int $tries = 3;

    public function backoff(): int
    {
        return 5;
    }

    public function __construct(int $orderId, int $paymentId)
    {
        $this->orderId   = $orderId;
        $this->paymentId = $paymentId;
        // If you want a dedicated queue, uncomment:
        // $this->onQueue('payments');
    }

    /**
     * Extra guard against concurrent handling across workers.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->dontRelease(),
        ];
    }

    public function handle(): void
    {
        /** @var Order|null $order */
        $order = Order::query()->with(['items.product', 'customer'])->find($this->orderId);
        if (!$order) {
            throw new ModelNotFoundException("SimulatePaymentJob: Order {$this->orderId} not found.");
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()->where('id', $this->paymentId)
            ->where('order_id', $order->id)
            ->first();

        if (!$payment) {
            throw new ModelNotFoundException("SimulatePaymentJob: Payment {$this->paymentId} for Order {$order->id} not found.");
        }

        // Idempotency: if already terminal, do nothing.
        if (in_array($payment->status, ['success', 'failed'], true)) {
            Log::info("SimulatePaymentJob: Payment {$payment->id} already {$payment->status}; skipping.");
            return;
        }

        // Determine outcome (configurable for tests)
        $forced = strtolower((string) config('payments.force_outcome', env('PAYMENT_FORCE_OUTCOME', '')));
        $successRate = (float) env('PAYMENT_SUCCESS_RATE', 0.9); // 90% default
        $willSucceed = $this->decideOutcome($forced, $successRate);

        // Persist payment outcome atomically
        DB::transaction(function () use ($payment, $willSucceed) {
            // Re-check inside the txn to avoid races
            $p = Payment::lockForUpdate()->find($payment->id);
            if (!$p) {
                throw new ModelNotFoundException("SimulatePaymentJob: Payment {$payment->id} not found in txn.");
            }
            if (in_array($p->status, ['success', 'failed'], true)) {
                // Another worker finished first
                return;
            }

            $p->status = $willSucceed ? 'success' : 'failed';
            $p->save();
        }, 3);

        // Fan out next step
        if ($willSucceed) {
            Log::info("SimulatePaymentJob: Payment {$payment->id} succeeded; dispatching FinalizeOrderJob for Order {$order->id}.");
            FinalizeOrderJob::dispatch($order->id);
        } else {
            Log::warning("SimulatePaymentJob: Payment {$payment->id} failed; dispatching RollbackOrderJob for Order {$order->id}.");
            RollbackOrderJob::dispatch($order->id);
        }
    }

    /**
     * Decide success/failure based on forced outcome or probability.
     */
    private function decideOutcome(string $forced, float $successRate): bool
    {
        if ($forced === 'success') {
            return true;
        }
        if ($forced === 'failed' || $forced === 'fail') {
            return false;
        }

        // Clamp successRate to [0,1]
        $successRate = max(0.0, min(1.0, $successRate));

        // Use cryptographically secure random_int for fair draw
        $scale = 10000; // precision
        $draw = random_int(1, $scale);
        return $draw <= (int) round($successRate * $scale);
    }
}
