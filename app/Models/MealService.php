<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealService extends Model
{
    protected $table = 'meal_services';
    protected $primaryKey = 'meal_service_id';
    protected $guarded = [];

    protected $casts = [
        'pax' => 'integer',
        'price_per_head' => 'float',
    ];

    protected $appends = ['service_date', 'day_number', 'total_meal_amount', 'menu_name', 'menu_description'];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function eventDay()
    {
        return $this->belongsTo(EventDay::class, 'event_day_id', 'event_day_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id', 'menu_item_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id', 'package_id');
    }

    public function filters()
    {
        return $this->hasMany(MealServiceFilter::class, 'meal_service_id', 'meal_service_id');
    }

    public function customItems()
    {
        return $this->hasMany(MealServiceCustomItem::class, 'meal_service_id', 'meal_service_id');
    }

    public function getServiceDateAttribute()
    {
        return $this->eventDay?->date;
    }

    public function getDayNumberAttribute(): int
    {
        return (int) ($this->eventDay?->day_number ?? 1);
    }

    public function getTotalMealAmountAttribute(): float
    {
        return (float) $this->pax * (float) $this->price_per_head;
    }

    public function getMenuNameAttribute(): ?string
    {
        if ($this->menu_source === 'package') {
            return $this->package?->name;
        }

        return $this->menuItem?->name
            ?: $this->customItems->pluck('item_name')->filter()->implode(', ')
            ?: null;
    }

    public function getMenuDescriptionAttribute(): ?string
    {
        if ($this->menu_source === 'package') {
            return $this->package?->description;
        }

        return $this->customItems->pluck('description')->filter()->implode("\n");
    }
}
