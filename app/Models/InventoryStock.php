<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    protected $table = 'inventory_stocks';
    protected $primaryKey = 'stock_id';
    protected $guarded = [];

    protected $casts = [
        'current_quantity' => 'float',
        'reserved_quantity' => 'float',
        'minimum_quantity' => 'float',
        'maximum_quantity' => 'float',
        'max_stock_level' => 'float',
        'reorder_point' => 'float',
        'expiry_date' => 'date',
        'last_restocked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id', 'ingredient_id');
    }

    public function getAvailableQuantityAttribute()
    {
        return max(0, $this->current_quantity - $this->reserved_quantity);
    }

    public function getStockStatusAttribute()
    {
        $available = $this->available_quantity;
        
        if ($available <= 0) {
            return 'out_of_stock';
        }
        if ($available <= $this->reorder_point) {
            return 'low_stock';
        }
        if ($available >= $this->maximum_quantity) {
            return 'over_stock';
        }
        return 'in_stock';
    }

    public function decrementReserved($quantity)
    {
        $this->decrement('reserved_quantity', $quantity);
        $this->decrement('current_quantity', $quantity);
        $this->update(['last_used_at' => now()]);
    }

    public function incrementReserved($quantity)
    {
        $this->increment('reserved_quantity', $quantity);
    }
}