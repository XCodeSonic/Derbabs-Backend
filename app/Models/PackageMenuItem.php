<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageMenuItem extends Model
{
    protected $table = 'package_menu_items';
    protected $primaryKey = 'package_menu_item_id';
    protected $guarded = [];

    protected $casts = [
        'quantity_per_pax' => 'integer',
        'is_optional' => 'boolean',
        'is_replaceable' => 'boolean',
        'additional_cost' => 'float',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id', 'package_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id', 'menu_item_id');
    }
}
