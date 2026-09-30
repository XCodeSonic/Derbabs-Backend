<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDay extends Model
{
    protected $table = 'event_days';
    protected $primaryKey = 'event_day_id';
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
    ];

    protected $appends = ['day_total_amount'];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function mealServices()
    {
        return $this->hasMany(MealService::class, 'event_day_id', 'event_day_id');
    }

    public function getDayTotalAmountAttribute(): float
    {
        return (float) $this->mealServices->sum(fn ($meal) => (float) $meal->total_meal_amount);
    }
}
