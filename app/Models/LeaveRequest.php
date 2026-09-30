<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use SoftDeletes;

    protected $table = 'leave_requests';
    protected $primaryKey = 'leave_request_id';
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    protected $appends = [
        'id',
        'type',
        'employee_name',
        'employee_code',
        'request_date',
        'swap_summary',
    ];

    /* =========================================================
       RELATIONSHIPS
       ========================================================= */

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function swapFromEmployee()
    {
        return $this->belongsTo(Employee::class, 'swap_from_employee_id', 'employee_id');
    }

    public function swapToEmployee()
    {
        return $this->belongsTo(Employee::class, 'swap_to_employee_id', 'employee_id');
    }

    /* =========================================================
       BASIC ACCESSORS
       ========================================================= */

    public function getIdAttribute()
    {
        return $this->leave_request_id;
    }

    public function getTypeAttribute(): ?string
    {
        return $this->request_type;
    }

    public function getEmployeeNameAttribute(): string
    {
        return $this->employee?->full_name ?: 'N/A';
    }

    public function getEmployeeCodeAttribute(): ?string
    {
        return $this->employee?->employee_code;
    }

    public function getRequestDateAttribute(): ?string
    {
        return $this->created_at?->toDateString();
    }

    /**
     * ⭐ FIX #4: Human-readable shift swap summary
     * Example: "Sep 22, 2026 · 9:00 AM - 4:00 PM → 5:00 PM - 12:00 AM (Jefferson → Adriane)"
     */
    public function getSwapSummaryAttribute(): ?string
    {
        if ($this->request_type !== 'swap') {
            return null;
        }

        $date = $this->start_date
            ? \Carbon\Carbon::parse($this->start_date)->format('M d, Y')
            : '—';

        $from = $this->swap_from_time ?? '—';
        $to = $this->swap_to_time ?? '—';

        $fromEmp = $this->swap_from_employee_name
            ?: ($this->swapFromEmployee?->full_name ?? null);
        $toEmp = $this->swap_to_employee_name
            ?: ($this->swapToEmployee?->full_name ?? null);

        $empPart = ($fromEmp || $toEmp)
            ? ' (' . ($fromEmp ?: '—') . ' → ' . ($toEmp ?: '—') . ')'
            : '';

        return "{$date} · {$from} → {$to}{$empPart}";
    }
}