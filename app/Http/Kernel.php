<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ⭐ Expire admin-initiated reschedule proposals that the customer
        //    has not responded to within the configured window (default: 24h).
        //    This AUTO-CANCELS the booking and moves it out of active lists.
        $schedule->call(function () {
            $count = app(\App\Services\BookingService::class)->expireCustomerRescheduleResponses();
            if ($count > 0) {
                \Illuminate\Support\Facades\Log::info("Expired {$count} customer reschedule response(s).");
            }
        })->hourly()->name('expire-customer-reschedule-responses');

        // ⭐ Flag customer-initiated reschedule requests that the admin
        //    has not responded to within the configured window (default: 48h).
        $schedule->call(function () {
            $count = app(\App\Services\BookingService::class)->expireAdminRescheduleResponses();
            if ($count > 0) {
                \Illuminate\Support\Facades\Log::warning("{$count} admin reschedule response(s) are overdue.");
            }
        })->hourly()->name('expire-admin-reschedule-responses');

        // ⭐ Auto-cancel bookings with unpaid deposits (2 days before event).
        $schedule->call(function () {
            $result = app(\App\Services\DepositService::class)->autoCancelUnpaidDeposits();
            if (($result['count'] ?? 0) > 0) {
                \Illuminate\Support\Facades\Log::info("Auto-cancelled {$result['count']} booking(s) due to unpaid deposit.");
            }
        })->dailyAt('08:00')->name('auto-cancel-unpaid-deposits');

        // ⭐ Send deposit reminders daily.
        $schedule->call(function () {
            $count = app(\App\Services\DepositService::class)->sendDepositReminders();
            if ($count > 0) {
                \Illuminate\Support\Facades\Log::info("Sent {$count} deposit reminder(s).");
            }
        })->dailyAt('09:00')->name('send-deposit-reminders');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}