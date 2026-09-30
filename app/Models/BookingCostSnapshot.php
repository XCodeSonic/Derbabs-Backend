<?php
// app/Models/BookingCostSnapshot.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCostSnapshot extends Model
{
    protected $table = 'booking_cost_snapshots';

    protected $fillable = [
        'booking_id',
        'ingredient_cost',
        'labor_cost',
        'delivery_cost',
        'equipment_cost',
        'other_cost',
        'food_revenue',
        'service_fee',
        'delivery_fee',
        'extras_revenue',
        'discount_amount',
        'ingredient_breakdown',
        'menu_breakdown',
        'snapshot_type',
        'snapshotted_at',
    ];

    protected $casts = [
        'ingredient_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'equipment_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
        'food_revenue' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'extras_revenue' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'ingredient_breakdown' => 'array',
        'menu_breakdown' => 'array',
        'snapshotted_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    // ==================== ACCESSORS ====================

    public function getTotalRevenueAttribute(): float
    {
        return (float) ($this->food_revenue + $this->service_fee + $this->delivery_fee + $this->extras_revenue - $this->discount_amount);
    }

    public function getTotalCostAttribute(): float
    {
        return (float) ($this->ingredient_cost + $this->labor_cost + $this->delivery_cost + $this->equipment_cost + $this->other_cost);
    }

    public function getProfitAttribute(): float
    {
        return $this->total_revenue - $this->total_cost;
    }

    public function getProfitMarginAttribute(): float
    {
        $revenue = $this->total_revenue;
        if ($revenue <= 0) {
            return 0;
        }
        return round(($this->profit / $revenue) * 100, 2);
    }

    public function getFoodCostPercentageAttribute(): float
    {
        $foodRevenue = (float) $this->food_revenue;
        if ($foodRevenue <= 0) {
            return 0;
        }
        return round(($this->ingredient_cost / $foodRevenue) * 100, 2);
    }
}