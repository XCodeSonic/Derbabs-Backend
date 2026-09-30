<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MealCategory extends Model
{
    use SoftDeletes;

    protected $table = 'meal_categories';
    protected $primaryKey = 'category_id';
    protected $guarded = [];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class, 'category_id', 'category_id');
    }
}