<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceEvent extends Model
{
    use SoftDeletes;

    protected $table = 'service_events';
    protected $primaryKey = 'service_event_id';
    protected $guarded = [];

    protected $casts = [
        'event_date' => 'date',
        'event_end_date' => 'date',
        'scheduled_delivery_time' => 'datetime',
        'has_waiters' => 'boolean',
        'delivery_fee' => 'float',
        'guests_count' => 'integer',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id', 'event_type_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id', 'package_id');
    }

    public function quotation()
    {
        return $this->hasOne(Quotation::class, 'service_event_id', 'service_event_id');
    }

    public function booking()
    {
        return $this->hasOne(Booking::class, 'service_event_id', 'service_event_id');
    }

    public function delivery()
    {
        return $this->hasOne(Delivery::class, 'service_event_id', 'service_event_id');
    }



    public function eventDays()
    {
        return $this->hasManyThrough(EventDay::class, Booking::class, 'service_event_id', 'booking_id', 'service_event_id', 'booking_id');
    }

    public function mealServices()
    {
        return $this->hasManyThrough(MealService::class, Booking::class, 'service_event_id', 'booking_id', 'service_event_id', 'booking_id');
    }

    public function deliveryZone()
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id', 'zone_id');
    }
}