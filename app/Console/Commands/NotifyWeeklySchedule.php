<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Models\Employee;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyWeeklySchedule extends Command
{
    protected $signature = 'notify:weekly-schedule';
    protected $description = 'Send weekly schedule reminders to employees';

    public function handle(NotificationService $notificationService)
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        
        $schedules = Schedule::with('employee')
            ->whereBetween('work_date', [$startOfWeek, $endOfWeek])
            ->get()
            ->groupBy('employee_id');
        
        $notifiedCount = 0;
        
        foreach ($schedules as $employeeId => $employeeSchedules) {
            $employee = Employee::find($employeeId);
            
            if ($employee && $employee->user_id) {
                $scheduleList = $employeeSchedules->map(function($s) {
                    return "• {$s->work_date->format('M d')}: {$s->start_time} - {$s->end_time}";
                })->implode("\n");
                
                $notificationService->notifyUser(
                    $employee->user_id,
                    'weekly_schedule',
                    '📅 Weekly Schedule',
                    "Your schedule for this week:\n{$scheduleList}",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['week_start' => $startOfWeek->toDateString(), 'week_end' => $endOfWeek->toDateString()],
                    "/employee/schedules"
                );
                
                $notifiedCount++;
            }
        }
        
        $this->info("Sent weekly schedule reminders to {$notifiedCount} employees.");
        
        return Command::SUCCESS;
    }
}