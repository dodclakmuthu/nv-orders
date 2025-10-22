<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'customer_id','status','subtotal','total','order_date','import_batch','order_number'
    ];

    protected $casts = [
        'order_date' => 'date',
        'subtotal'   => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Convenience attribute: sum of processed refunds.
     */
    public function getRefundedTotalAttribute(): float
    {
        return (float) $this->refunds()
            ->where('status', 'processed')
            ->sum('amount');
    }
}
