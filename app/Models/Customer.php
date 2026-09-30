<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'customers';
    protected $primaryKey = 'customer_id';
    protected $guarded = [];

    protected $casts = [
        'loyalty_points' => 'integer',
        'is_active' => 'boolean',
        'tier_valid_until' => 'date',
    ];

    // Relationships
    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id', 'person_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function serviceEvents()
    {
        return $this->hasMany(ServiceEvent::class, 'customer_id', 'customer_id');
    }


    public function chatThreads()
    {
        return $this->hasMany(ChatThread::class, 'customer_id', 'customer_id');
    }

    public function bookings()
    {
        return $this->hasManyThrough(
            Booking::class,
            ServiceEvent::class,
            'customer_id',
            'service_event_id',
            'customer_id',
            'service_event_id'
        );
    }
}