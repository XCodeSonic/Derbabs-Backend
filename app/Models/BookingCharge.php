<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCharge extends Model
{
    protected $table = 'booking_charges';
    protected $primaryKey = 'booking_charge_id';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }
}
