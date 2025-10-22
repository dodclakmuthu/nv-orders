<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OrderProcessedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        // $this->onQueue('notifications'); // optional
    }

    public function via($notifiable): array
    {
        // Default to log; switch to ['mail','log'] when mail is configured
        return ['log'];
    }

    public function toArray($notifiable): array
    {
        // Required shape per assignment
        return [
            'order_id'    => $this->data['order_id']    ?? null,
            'customer_id' => $this->data['customer_id'] ?? null,
            'status'      => $this->data['status']      ?? null,
            'total'       => $this->data['total']       ?? 0.0,
        ] + $this->data;
    }
}
