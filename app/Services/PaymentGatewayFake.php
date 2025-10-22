<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Jobs\SimulatePaymentJob;

class PaymentGatewayFake
{
    public function initiate(Order $order): Payment
    {
        $payment = Payment::create([
            'order_id' => $order->id,
            'status'   => 'initiated',
            'provider' => 'fakepay',
        ]);

        SimulatePaymentJob::dispatch($order->id, $payment->id)->delay(now()->addSeconds(2));
        return $payment;
    }
}
