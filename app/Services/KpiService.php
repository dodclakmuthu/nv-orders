<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Redis;

class KpiService
{
    private function dayKey(string $date): string { return "kpi:{$date}"; }
    private string $boardKey = 'leaderboard:customers';

    public function incrForOrderFinalized(Order $order): void
    {
        $k = $this->dayKey($order->order_date->toDateString());
        Redis::hincrbyfloat($k, 'revenue', (float)$order->total);
        Redis::hincrby($k, 'order_count', 1);
        $rev = (float) (Redis::hget($k, 'revenue') ?? 0);
        $cnt = (int) (Redis::hget($k, 'order_count') ?? 0);
        Redis::hset($k, 'avg_order_value', $cnt ? round($rev / $cnt, 2) : 0);

        Redis::zincrby($this->boardKey, (float)$order->total, (string)$order->customer_id);
    }

    public function applyRefund(Order $order, float $amount): void
    {
        $k = $this->dayKey($order->order_date->toDateString());
        Redis::hincrbyfloat($k, 'revenue', -$amount);
        $rev = (float) (Redis::hget($k, 'revenue') ?? 0);
        $cnt = (int) (Redis::hget($k, 'order_count') ?? 0);
        Redis::hset($k, 'avg_order_value', $cnt ? round($rev / $cnt, 2) : 0);

        Redis::zincrby($this->boardKey, -$amount, (string)$order->customer_id);
    }
}
