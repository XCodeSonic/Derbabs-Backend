<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    protected $table = 'quotations';
    protected $primaryKey = 'quotation_id';
    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'float',
        'valid_until' => 'date',
    ];

    public function serviceEvent()
    {
        return $this->belongsTo(ServiceEvent::class, 'service_event_id', 'service_event_id');
    }

    public function booking()
    {
        return $this->hasOne(Booking::class, 'quotation_id', 'quotation_id');
    }
}
