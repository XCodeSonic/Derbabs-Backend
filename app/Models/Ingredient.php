<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use SoftDeletes;

    protected $table = 'ingredients';
    protected $primaryKey = 'ingredient_id';
    protected $guarded = [];

    protected $casts = [
        'unit_cost' => 'float',
        'lead_time_days' => 'integer',
        'yield_percentage' => 'integer',
        'reuse_factor' => 'float',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function stock()
    {
        return $this->hasOne(InventoryStock::class, 'ingredient_id', 'ingredient_id');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'ingredient_id', 'ingredient_id');
    }

    public function recipes()
    {
        return $this->hasMany(RecipeIngredient::class, 'ingredient_id', 'ingredient_id');
    }

    public function purchaseRequests()
    {
        return $this->hasMany(PurchaseRequest::class, 'ingredient_id', 'ingredient_id');
    }

    public function wasteRecords()
    {
        return $this->hasMany(WasteRecord::class, 'ingredient_id', 'ingredient_id');
    }
}