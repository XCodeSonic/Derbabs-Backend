<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use SoftDeletes;

    protected $table = 'schedules';
    protected $primaryKey = 'schedule_id';
    protected $guarded = [];

    protected $casts = [
        'work_date' => 'date',
        'break_minutes' => 'float',
    ];

    protected $appends = [
        'id',
        'date',
        'shift_type',
        'type',
        'placement',
        'notes',
        'duration_hours',
        'duration',
        'employee_name',
        'employee_code',
        'hourly_rate',
        'total_cost',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function shiftTypeDefinition()
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id', 'shift_type_id');
    }

    public function attendanceLog()
    {
        return $this->hasOne(AttendanceLog::class, 'schedule_id', 'schedule_id');
    }

    public function getIdAttribute()
    {
        return $this->schedule_id;
    }

    public function getDateAttribute(): ?string
    {
        return $this->work_date?->format('Y-m-d');
    }

    public function getShiftTypeAttribute(): string
    {
        return $this->shiftTypeDefinition?->slug ?? 'regular';
    }

    public function getTypeAttribute(): string
    {
        return $this->shift_type;
    }

    public function getPlacementAttribute(): string
    {
        return (string) ($this->assignmentPayload()['placement'] ?? '');
    }

    public function getNotesAttribute(): string
    {
        return (string) ($this->assignmentPayload()['notes'] ?? '');
    }

    public function getDurationHoursAttribute(): float
    {
        if (! $this->start_time || ! $this->end_time) {
            return 0;
        }

        $start = Carbon::parse('2000-01-01 ' . $this->start_time);
        $end = Carbon::parse('2000-01-01 ' . $this->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $minutes = max(0, $start->diffInMinutes($end) - (int) round((float) $this->break_minutes));

        return round($minutes / 60, 2);
    }

    public function getDurationAttribute(): float
    {
        return $this->duration_hours;
    }

    public function getEmployeeNameAttribute(): string
    {
        return $this->employee?->full_name ?: 'N/A';
    }

    public function getEmployeeCodeAttribute(): ?string
    {
        return $this->employee?->employee_code;
    }

    public function getHourlyRateAttribute(): float
    {
        return round((float) ($this->employee?->calculated_hourly_rate ?? 0), 2);
    }

    public function getTotalCostAttribute(): float
    {
        return round($this->duration_hours * $this->hourly_rate, 2);
    }

    /**
     * Get the assignment payload from assignment_details field
     * This method is used by the getPlacementAttribute and getNotesAttribute accessors
     */
    public function assignmentPayload(): array
    {
        if (!$this->assignment_details) {
            return [];
        }

        $decoded = json_decode($this->assignment_details, true);

        return is_array($decoded) ? $decoded : ['notes' => $this->assignment_details];
    }
}
