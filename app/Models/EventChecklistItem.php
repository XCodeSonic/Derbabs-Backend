<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventChecklistItem extends Model
{
    protected $table = 'event_checklist_items';
    protected $primaryKey = 'event_checklist_item_id';
    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'manual_override' => 'boolean',
        'completed_at' => 'datetime',
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
