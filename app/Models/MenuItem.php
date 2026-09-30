<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class MenuItem extends Model
{
    use SoftDeletes;

    protected $table = 'menu_items';
    protected $primaryKey = 'menu_item_id';
    protected $guarded = [];

    protected $casts = [
        'price' => 'float',
        'cost_to_make' => 'float',
        'prep_time_minutes' => 'integer',
        'serving_size' => 'integer',
        'is_available' => 'boolean',
        'is_popular' => 'boolean',
        'is_vegetarian' => 'boolean',
        'is_vegan' => 'boolean',
        'is_gluten_free' => 'boolean',
        'is_halal' => 'boolean',
        // New tray pricing casts
        'tray_price' => 'float',
        'tray_servings' => 'integer',
        'tray_min_pax' => 'integer',
        'tray_max_pax' => 'integer',
        'pricing_type' => 'string',
    ];

    protected $appends = [
        'image_full_url',
        'tray_display_description',
        'has_tray_pricing',
        'has_per_pax_pricing',
        'pricing_label'
    ];

    // ==================== RELATIONSHIPS ====================

    public function category()
    {
        return $this->belongsTo(MealCategory::class, 'category_id', 'category_id');
    }

    public function recipeIngredients()
    {
        return $this->hasMany(RecipeIngredient::class, 'menu_item_id', 'menu_item_id');
    }

    public function packageItems()
    {
        return $this->hasMany(PackageMenuItem::class, 'menu_item_id', 'menu_item_id');
    }

    public function packages()
    {
        return $this->belongsToMany(
            Package::class,
            'package_menu_items',
            'menu_item_id',
            'package_id',
            'menu_item_id',
            'package_id'
        )->withPivot([
            'package_menu_item_id',
            'quantity_per_pax',
            'is_optional',
            'is_replaceable',
            'additional_cost',
        ])->withTimestamps();
    }

    public function bookingItems()
    {
        return $this->hasMany(BookingItem::class, 'menu_item_id', 'menu_item_id');
    }

    // ==================== ACCESSORS ====================

    public function getImageFullUrlAttribute()
    {
        if (!$this->image_url) {
            return null;
        }

        if (str_starts_with($this->image_url, 'data:image/') || filter_var($this->image_url, FILTER_VALIDATE_URL)) {
            return $this->image_url;
        }

        if (Storage::disk('public')->exists($this->image_url)) {
            return Storage::disk('public')->url($this->image_url);
        }

        return null;
    }

    /**
     * Get the pricing display label
     */
    public function getPricingLabelAttribute(): string
    {
        $labels = [
            'per_pax' => 'Per Pax',
            'per_tray' => 'Per Tray',
            'both' => 'Both',
        ];
        return $labels[$this->pricing_type] ?? 'Per Pax';
    }

    /**
     * Check if item has tray pricing
     */
    public function hasTrayPricing(): bool
    {
        return in_array($this->pricing_type, ['per_tray', 'both']) && $this->tray_price > 0;
    }

    /**
     * Check if item has per pax pricing
     */
    public function hasPerPaxPricing(): bool
    {
        return in_array($this->pricing_type, ['per_pax', 'both']) && $this->price > 0;
    }

    /**
     * Get tray description with pax range
     */
    public function getTrayDisplayDescriptionAttribute(): ?string
    {
        if ($this->tray_description) {
            return $this->tray_description;
        }

        if ($this->tray_min_pax && $this->tray_max_pax) {
            return "Good for {$this->tray_min_pax}–{$this->tray_max_pax} pax";
        }

        return null;
    }

    /**
     * Get the formatted tray display for frontend
     */
    public function getTrayDisplayAttribute(): ?string
    {
        if ($this->tray_price > 0) {
            $desc = $this->tray_display_description;
            return $desc ? "₱" . number_format($this->tray_price, 2) . " (Tray) " . $desc : "₱" . number_format($this->tray_price, 2) . " (Tray)";
        }
        return null;
    }

    /**
     * Get the formatted per pax display for frontend
     */
    public function getPerPaxDisplayAttribute(): ?string
    {
        if ($this->price > 0) {
            return "₱" . number_format($this->price, 2) . " (Pax)";
        }
        return null;
    }
}
