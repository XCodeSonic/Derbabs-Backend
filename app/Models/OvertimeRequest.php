<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    protected $table = 'overtime_requests';
    protected $primaryKey = 'overtime_request_id';
    protected $guarded = [];

    protected $casts = [
        'hours' => 'float',
        'approved_at' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(AttendanceLog::class, 'attendance_id', 'attendance_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }
}
