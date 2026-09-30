<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealServiceFilter extends Model
{
    protected $table = 'meal_service_filters';
    protected $primaryKey = 'meal_service_filter_id';
    protected $guarded = [];

    public function mealService()
    {
        return $this->belongsTo(MealService::class, 'meal_service_id', 'meal_service_id');
    }
}
