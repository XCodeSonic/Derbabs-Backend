<?php

namespace App\Console\Commands;

use App\Models\BookingEquipment;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyEquipmentOverdue extends Command
{
    protected $signature = 'notify:equipment-overdue';
    protected $description = 'Check for overdue equipment returns';

    public function handle(NotificationService $notificationService)
    {
        $overdueItems = BookingEquipment::where('status', 'checked_out')
            ->whereDate('rental_end_date', '<', Carbon::today())
            ->with(['booking', 'equipment'])
            ->get();
        
        foreach ($overdueItems as $item) {
            $notificationService->equipmentReturnOverdue($item, $item->booking);
        }
        
        $this->info("Notified about {$overdueItems->count()} overdue equipment items.");
        
        return Command::SUCCESS;
    }
}