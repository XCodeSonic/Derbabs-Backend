<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftType extends Model
{
    protected $table = 'shift_types';
    protected $primaryKey = 'shift_type_id';
    protected $guarded = [];

    protected $casts = [
        'break_minutes' => 'float',
        'night_differential_rate' => 'float',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'id',
        'code',
        'start_time',
        'end_time',
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'shift_type_id', 'shift_type_id');
    }

    public function getIdAttribute()
    {
        return $this->shift_type_id;
    }

    public function getCodeAttribute(): string
    {
        return $this->slug;
    }

    public function getStartTimeAttribute(): string
    {
        return $this->default_start_time;
    }

    public function getEndTimeAttribute(): string
    {
        return $this->default_end_time;
    }
}
