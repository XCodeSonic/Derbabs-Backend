<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;

    protected $table = 'deliveries';
    protected $primaryKey = 'delivery_id';
    protected $guarded = [];

    protected $casts = [
        'scheduled_pickup' => 'datetime',
        'scheduled_delivery' => 'datetime',
        'actual_pickup' => 'datetime',
        'actual_delivery' => 'datetime',
        'additional_charges' => 'float',
        'origin_latitude' => 'float',
        'origin_longitude' => 'float',
        'destination_latitude' => 'float',
        'destination_longitude' => 'float',
    ];

    public function serviceEvent()
    {
        return $this->belongsTo(ServiceEvent::class, 'service_event_id', 'service_event_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id', 'user_id');
    }
}
