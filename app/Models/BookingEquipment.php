<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingEquipment extends Model
{
    protected $table = 'booking_equipment';
    protected $primaryKey = 'booking_equipment_id';
    protected $guarded = [];

    protected $casts = [
        'quantity_reserved' => 'integer',
        'quantity_used' => 'integer',
        'quantity_damaged' => 'integer',
        'quantity_missing' => 'integer',
        'rental_start_date' => 'date',
        'rental_end_date' => 'date',
        'rental_price_at_booking' => 'float',
        'checked_out_date' => 'date',
        'checked_in_date' => 'date',
        'is_out_approved' => 'boolean',
        'out_approved_at' => 'datetime',
        'damage_charge' => 'float',
        'missing_charge' => 'float',
    ];

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id', 'equipment_id');
    }
}