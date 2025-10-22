<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['sku','name','stock','reserved','sold','price'];

    protected $casts = [
        'stock'    => 'integer',
        'reserved' => 'integer',
        'sold'     => 'integer',
        'price'    => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
