<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealServiceCustomItem extends Model
{
    protected $table = 'meal_service_custom_items';
    protected $primaryKey = 'meal_service_custom_item_id';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
    ];

    public function mealService()
    {
        return $this->belongsTo(MealService::class, 'meal_service_id', 'meal_service_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id', 'menu_item_id');
    }
}
