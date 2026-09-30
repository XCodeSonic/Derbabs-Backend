<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDeliveryTracking extends Model
{
    protected $table = 'event_delivery_trackings';
    protected $primaryKey = 'event_delivery_tracking_id';
    protected $guarded = [];

    protected $casts = [
        'delivery_date' => 'date',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function mealService()
    {
        return $this->belongsTo(MealService::class, 'meal_service_id', 'meal_service_id');
    }
}
