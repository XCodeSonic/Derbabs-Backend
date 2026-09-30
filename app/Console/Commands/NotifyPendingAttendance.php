<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class NotifyPendingAttendance extends Command
{
    protected $signature = 'notify:pending-attendance';
    protected $description = 'Notify admin about pending attendance verifications';

    public function handle(NotificationService $notificationService)
    {
        $pendingCount = AttendanceLog::where('approval_status', 'pending')
            ->whereDate('attendance_date', '<=', now()->subDay())
            ->count();
        
        if ($pendingCount > 0) {
            $notificationService->attendancePendingVerification($pendingCount);
            $this->info("Notified about {$pendingCount} pending attendance records.");
        } else {
            $this->info("No pending attendance records.");
        }
        
        return Command::SUCCESS;
    }
}