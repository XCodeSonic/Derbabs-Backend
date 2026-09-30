<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'package_id';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'base_price_per_pax',
        'price_per_additional_pax',
        'min_pax',
        'max_pax',
        'default_duration_hours',
        'inclusions',
        'exclusions',
        'sort_order',
        'is_active',
        'is_featured',
    ];

    protected $casts = [
        'base_price_per_pax' => 'decimal:2',
        'price_per_additional_pax' => 'decimal:2',
        'min_pax' => 'integer',
        'max_pax' => 'integer',
        'default_duration_hours' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'inclusions' => 'array',
        'exclusions' => 'array',
    ];

    public function menuItems()
    {
        return $this->belongsToMany(
            MenuItem::class,
            'package_menu_items',
            'package_id',
            'menu_item_id'
        )->withPivot([
            'quantity_per_pax',
            'is_optional',
            'is_replaceable',
            'additional_cost'
        ])->withTimestamps();
    }
}