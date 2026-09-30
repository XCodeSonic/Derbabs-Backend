<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';
    protected $primaryKey = 'order_item_id';
    protected $guarded = [];

    protected $casts = [
        'unit_price_snapshot' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id', 'menu_item_id');
    }

    public function mealService()
    {
        return $this->belongsTo(MealService::class, 'meal_service_id', 'meal_service_id');
    }
}