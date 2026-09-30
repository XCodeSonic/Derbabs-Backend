<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingPayment extends Model
{
    use SoftDeletes;

    protected $table = 'booking_payments';
    protected $primaryKey = 'payment_id';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',    
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
    ];

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }
}