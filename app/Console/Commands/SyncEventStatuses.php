<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\EventTracking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncEventStatuses extends Command
{
    protected $signature = 'events:sync-status';
    protected $description = 'Synchronize event statuses (mark ongoing/completed)';

    public function handle(NotificationService $notificationService)
    {
        $today = Carbon::today();
        
        // Mark events as ongoing that start today
        $startingToday = Booking::whereHas('serviceEvent', function($q) use ($today) {
                $q->whereDate('event_date', $today);
            })
            ->where('booking_status', 'confirmed')
            ->get();
        
        foreach ($startingToday as $booking) {
            $booking->update(['booking_status' => 'ongoing']);
            $booking->serviceEvent?->update(['status' => 'ongoing']);
            
            // Update tracking
            EventTracking::updateOrCreate(
                ['booking_id' => $booking->booking_id, 'stage' => 'ongoing'],
                ['stage_started_at' => now(), 'progress_percentage' => 0]
            );
            
            // Send event started notification
            $notificationService->eventStarted($booking);
            
            $this->line("Marked event {$booking->booking_no} as ongoing");
        }
        
        // Mark events as completed that ended yesterday
        $yesterday = Carbon::yesterday();
        $completedYesterday = Booking::whereHas('serviceEvent', function($q) use ($yesterday) {
                $q->whereDate('event_end_date', $yesterday)
                    ->orWhere(function($sub) use ($yesterday) {
                        $sub->whereNull('event_end_date')
                            ->whereDate('event_date', $yesterday);
                    });
            })
            ->where('booking_status', 'ongoing')
            ->get();
        
        foreach ($completedYesterday as $booking) {
            $booking->update(['booking_status' => 'completed']);
            $booking->serviceEvent?->update(['status' => 'completed']);
            
            // Send notification for equipment return
            if ($booking->equipment()->where('status', 'checked_out')->exists()) {
                $notificationService->equipmentReturnPending($booking);
            }
            
            $this->line("Marked event {$booking->booking_no} as completed");
        }
        
        $this->info("Synced: {$startingToday->count()} started, {$completedYesterday->count()} completed");
        
        return Command::SUCCESS;
    }
}