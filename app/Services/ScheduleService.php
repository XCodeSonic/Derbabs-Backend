<?php

namespace App\Services;

use App\Models\{Schedule, LeaveRequest};
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ScheduleService
{
    public function assertAvailable(int $employeeId, string $date, string $start, string $end, ?int $ignore = null): void
    {
        $leave = LeaveRequest::where('employee_id', $employeeId)->where('status', 'approved')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists();
        if ($leave) throw new RuntimeException('Employee has approved leave/day off/sick leave.');
        $conflict = Schedule::where('employee_id', $employeeId)->whereDate('work_date', $date)->when($ignore, fn($q) => $q->where('schedule_id', '!=', $ignore))->where(fn($q) => $q->where('start_time', '<', $end)->where('end_time', '>', $start))->exists();
        if ($conflict) throw new RuntimeException('Employee has overlapping schedule.');
    }
    public function create(array $data): Schedule
    {
        return DB::transaction(function () use ($data) {
            $this->assertAvailable($data['employee_id'], $data['work_date'], $data['start_time'], $data['end_time']);
            return Schedule::create($data);
        });
    }
}
