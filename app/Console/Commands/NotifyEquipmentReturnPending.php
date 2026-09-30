<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyEquipmentReturnPending extends Command
{
    protected $signature = 'notify:equipment-return-pending';
    protected $description = 'Notify when events are completed and equipment needs return';

    public function handle(NotificationService $notificationService)
    {
        $yesterday = Carbon::yesterday()->toDateString();
        
        $completedEvents = Booking::where('booking_status', 'completed')
            ->whereDate('updated_at', $yesterday)
            ->with('equipment')
            ->get();
        
        foreach ($completedEvents as $booking) {
            $hasOutstandingEquipment = $booking->equipment->where('status', 'checked_out')->count() > 0;
            
            if ($hasOutstandingEquipment) {
                $notificationService->equipmentReturnPending($booking);
            }
        }
        
        $this->info("Notified about equipment return for {$completedEvents->count()} completed events.");
        
        return Command::SUCCESS;
    }
}