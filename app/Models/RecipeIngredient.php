<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $table = 'recipe_ingredients';
    protected $primaryKey = 'recipe_ingredient_id';
    protected $guarded = [];

    protected $casts = [
        'quantity_per_pax' => 'float',
    ];

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id', 'menu_item_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id', 'ingredient_id');
    }
}
