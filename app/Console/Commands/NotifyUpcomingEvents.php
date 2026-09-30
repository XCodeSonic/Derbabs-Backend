<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyUpcomingEvents extends Command
{
    protected $signature = 'notify:upcoming-events';
    protected $description = 'Send reminders for events starting in 3 days';

    public function handle(NotificationService $notificationService)
    {
        $targetDate = Carbon::today()->addDays(3)->toDateString();
        
        $upcomingEvents = Booking::whereHas('serviceEvent', function($q) use ($targetDate) {
                $q->whereDate('event_date', $targetDate);
            })
            ->whereIn('booking_status', ['confirmed', 'ongoing'])
            ->get();
        
        foreach ($upcomingEvents as $booking) {
            $notificationService->upcomingEventReminder($booking, 3);
        }
        
        $this->info("Sent reminders for {$upcomingEvents->count()} upcoming events.");
        
        return Command::SUCCESS;
    }
}