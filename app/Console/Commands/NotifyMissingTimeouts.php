<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class NotifyMissingTimeouts extends Command
{
    protected $signature = 'notify:missing-timeouts';
    protected $description = 'Check for employees with missing time-outs from previous day';

    public function handle(NotificationService $notificationService)
    {
        $yesterday = now()->subDay()->toDateString();
        
        $missingTimeouts = AttendanceLog::whereDate('attendance_date', $yesterday)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->with('employee')
            ->get();
        
        $notifiedCount = 0;
        
        foreach ($missingTimeouts as $attendance) {
            if ($attendance->employee) {
                $notificationService->missingTimeoutAlert($attendance->employee, $attendance);
                $notifiedCount++;
            }
        }
        
        $this->info("Notified about {$notifiedCount} missing time-outs from {$yesterday}.");
        
        return Command::SUCCESS;
    }
}