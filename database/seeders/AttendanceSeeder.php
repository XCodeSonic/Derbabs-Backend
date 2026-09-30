<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::with(['schedules'])->get();
        $approverIds = User::query()->pluck('user_id')->values();
        
        if ($employees->isEmpty()) {
            $this->command->warn('No employees found. Please run EmployeeSeeder first.');
            return;
        }

        // Generate attendance for the last 3 months
        $startDate = Carbon::now()->subMonths(3)->startOfMonth();
        $endDate = Carbon::now()->endOfDay();

        $statuses = ['present', 'present', 'present', 'present', 'late', 'late', 'absent', 'absent'];

        foreach ($employees as $employee) {
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                // Skip weekends (Saturday and Sunday)
                $dayOfWeek = $currentDate->dayOfWeek;
                if ($dayOfWeek === 6 || $dayOfWeek === 0) {
                    $currentDate->addDay();
                    continue;
                }

                // Preserve existing operational or analytics records on reruns.
                if (AttendanceLog::withTrashed()
                    ->where('employee_id', $employee->employee_id)
                    ->whereDate('attendance_date', $currentDate->toDateString())
                    ->exists()) {
                    $currentDate->addDay();
                    continue;
                }

                // 20% chance of absence on any given day
                $isAbsent = rand(1, 100) <= 20;
                
                if ($isAbsent) {
                    // Create absent record
                    AttendanceLog::create([
                        'employee_id' => $employee->employee_id,
                        'attendance_date' => $currentDate->toDateString(),
                        'status' => 'absent',
                        'approval_status' => 'approved',
                        'created_at' => $currentDate->copy()->setTime(rand(8, 9), rand(0, 59)),
                        'updated_at' => $currentDate->copy()->setTime(rand(8, 9), rand(0, 59)),
                    ]);
                    
                    $currentDate->addDay();
                    continue;
                }

                // Get schedule for this employee on this date
                $schedule = Schedule::where('employee_id', $employee->employee_id)
                    ->whereDate('work_date', $currentDate->toDateString())
                    ->first();

                // If no schedule, use default 8:00 AM - 5:00 PM
                $startHour = 8;
                $startMinute = 0;
                $endHour = 17;
                $endMinute = 0;
                $breakMinutes = 60;

                if ($schedule) {
                    $startTime = Carbon::parse($schedule->start_time);
                    $endTime = Carbon::parse($schedule->end_time);
                    $startHour = $startTime->hour;
                    $startMinute = $startTime->minute;
                    $endHour = $endTime->hour;
                    $endMinute = $endTime->minute;
                    $breakMinutes = (float) $schedule->break_minutes;
                }

                // Determine if employee is late
                $isLate = rand(1, 100) <= 25;
                
                // Time in
                $timeIn = Carbon::parse($currentDate->toDateString())
                    ->setTime($startHour, $startMinute, 0);
                
                if ($isLate) {
                    $timeIn->addMinutes(rand(5, 45));
                }

                // Time out (normal work hours)
                $timeOut = Carbon::parse($currentDate->toDateString())
                    ->setTime($endHour, $endMinute, 0);
                
                // Sometimes employee leaves early (undertime)
                $isUnderTime = rand(1, 100) <= 15;
                if ($isUnderTime) {
                    $timeOut->subMinutes(rand(15, 90));
                }

                // Sometimes employee works overtime
                $hasOvertime = rand(1, 100) <= 10;
                if ($hasOvertime) {
                    $timeOut->addMinutes(rand(30, 120));
                }

                // Calculate hours
                $workedMinutes = $timeIn->diffInMinutes($timeOut);
                $breakMinutesValue = (int) round($breakMinutes);
                $netMinutes = max(0, $workedMinutes - $breakMinutesValue);
                $hours = round($netMinutes / 60, 2);
                
                // Scheduled hours
                $scheduledMinutes = Carbon::parse($currentDate->toDateString() . ' ' . $startHour . ':' . $startMinute)
                    ->diffInMinutes(Carbon::parse($currentDate->toDateString() . ' ' . $endHour . ':' . $endMinute));
                $scheduledHours = round($scheduledMinutes / 60, 2);
                
                // Calculate regular and overtime hours
                $regularHours = min($hours, $scheduledHours);
                $overtimeHours = max(0, $hours - $scheduledHours);
                $undertimeHours = max(0, $scheduledHours - $hours);

                // Determine approval status (80% approved, 10% pending, 10% rejected)
                $randApproval = rand(1, 100);
                if ($randApproval <= 80) {
                    $approvalStatus = 'approved';
                } elseif ($randApproval <= 90) {
                    $approvalStatus = 'pending';
                } else {
                    $approvalStatus = 'rejected';
                }

                // Determine if overtime is approved
                $overtimeApproved = $hasOvertime && $overtimeHours > 0 && rand(1, 100) <= 70;

                // Determine status
                $status = 'present';
                if ($isLate && $hours >= $scheduledHours * 0.8) {
                    $status = 'late';
                } elseif ($isLate || $undertimeHours > 0) {
                    $status = 'late';
                } elseif ($hours < $scheduledHours * 0.5) {
                    $status = 'absent';
                }

                // Create attendance record
                AttendanceLog::create([
                    'employee_id' => $employee->employee_id,
                    'schedule_id' => $schedule?->schedule_id,
                    'attendance_date' => $currentDate->toDateString(),
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'break_start' => $timeIn->copy()->addMinutes(rand(120, 180)),
                    'break_end' => $timeIn->copy()->addMinutes(rand(180, 240)),
                    'regular_hours' => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'undertime_hours' => round($undertimeHours, 2),
                    'overtime_approved' => $overtimeApproved,
                    'status' => $status,
                    'approval_status' => $approvalStatus,
                    'approved_by' => $approvalStatus === 'approved' && $approverIds->isNotEmpty()
                        ? $approverIds->random()
                        : null,
                    'approved_at' => $approvalStatus === 'approved' ? $currentDate->copy()->addDays(rand(1, 5)) : null,
                    'ip_address' => '192.168.' . rand(1, 255) . '.' . rand(1, 255),
                    'device_info' => 'Web Browser - ' . rand(1, 5),
                    'created_at' => $currentDate->copy()->setTime(rand(8, 9), rand(0, 59)),
                    'updated_at' => $currentDate->copy()->setTime(rand(8, 9), rand(0, 59)),
                ]);

                $currentDate->addDay();
            }
        }
    }
}