<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function reserve(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $p = Product::lockForUpdate()->find($item->product_id);
                $available = $p->stock - $p->reserved;
                if ($available < $item->qty) {
                    return false;
                }
                $p->reserved += $item->qty;
                $p->save();
            }
            $order->update(['status' => 'reserved']);
            return true;
        }, 3);
    }

    public function release(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $p = Product::lockForUpdate()->find($item->product_id);
                $p->reserved -= $item->qty;
                $p->save();
            }
        }, 3);
    }

    public function commit(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $p = Product::lockForUpdate()->find($item->product_id);
                $p->reserved -= $item->qty;
                $p->sold += $item->qty;
                $p->stock -= $item->qty;
                $p->save();
            }
            $order->update(['status' => 'finalized']);
        }, 3);
    }
}
