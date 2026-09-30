<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table      = 'carts';
    protected $primaryKey = 'cart_id';
    protected $keyType    = 'int';
    public    $incrementing = true;
    public    $timestamps   = true;

    protected $guarded = [];

    protected $casts = [
        'customer_id' => 'integer',
    ];

    // ============================================================
    // RELATIONS
    // ============================================================

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /**
     * Cart items — includes pricing_type + unit_price.
     */
    public function items()
    {
        return $this->hasMany(CartItem::class, 'cart_id', 'cart_id');
    }

    /**
     * Same as items() but ordered newest-first — handy for cart UIs.
     */
    public function orderedItems()
    {
        return $this->hasMany(CartItem::class, 'cart_id', 'cart_id')
            ->orderByDesc('cart_item_id');
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    /**
     * Total number of units in the cart (sum of quantities).
     */
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * Total price across all lines (unit_price × quantity).
     */
    public function getTotalAmountAttribute(): float
    {
        return (float) $this->items->sum(function ($item) {
            return ((float) ($item->unit_price ?? 0)) * ((int) ($item->quantity ?? 0));
        });
    }
}
