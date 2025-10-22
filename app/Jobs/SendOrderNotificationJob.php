<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\NotificationLog;
use App\Notifications\OrderProcessedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendOrderNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Order primary key and event type: success|failure|refund
     */
    public int $orderId;
    public string $type;

    /**
     * Optional extra data (e.g., refund amount) merged into payload.
     */
    public array $extra;

    /**
     * Tries/backoff for transient errors.
     */
    public int $tries = 3;

    public function backoff(): int
    {
        return 5;
    }

    /**
     * @param int $orderId
     * @param string $type 'success'|'failure'|'refund'
     * @param array $extra optional details, e.g. ['refund_amount' => 20.00]
     */
    public function __construct(int $orderId, string $type, array $extra = [])
    {
        $this->orderId = $orderId;
        $this->type    = $type;
        $this->extra   = $extra;
        // $this->onQueue('notifications'); // uncomment if you map a dedicated queue in Horizon
    }

    public function handle(): void
    {
        /** @var Order|null $order */
        $order = Order::query()->with(['customer'])->find($this->orderId);
        if (!$order) {
            throw new ModelNotFoundException("SendOrderNotificationJob: Order {$this->orderId} not found.");
        }

        $payload = array_merge([
            'order_id'    => $order->id,
            'customer_id' => $order->customer_id,
            'status'      => $this->normalizeStatus($order->status, $this->type),
            'total'       => (float) $order->total,
        ], $this->extra);

        // === Send notification (LOG by default) ===
        // If you want email later, switch to Notification::route('mail', $order->customer->email)…
        $notifiable = (new AnonymousNotifiable())->route('log', 'system'); // goes to laravel.log via log channel
        Notification::send($notifiable, new OrderProcessedNotification($payload));

        // Also log to app log for local visibility
        Log::info("Order notification queued/sent", ['type' => $this->type, 'payload' => $payload]);

        // === Persist history ===
        NotificationLog::create([
            'order_id'    => $order->id,
            'customer_id' => $order->customer_id,
            'type'        => $this->type,              // success|failure|refund
            'payload'     => $payload,                 // cast to JSON in the model or migration
            'sent_at'     => now(),
        ]);
    }

    /**
     * Prefer the explicit job type for status; fall back to order->status if needed.
     */
    private function normalizeStatus(string $orderStatus, string $jobType): string
    {
        $map = [
            'success' => 'processed',
            'failure' => 'failed',
            'refund'  => 'refunded',
        ];
        return $map[$jobType] ?? $orderStatus;
    }
}
