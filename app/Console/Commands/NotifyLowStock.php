<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class NotifyLowStock extends Command
{
    protected $signature = 'notify:low-stock';
    protected $description = 'Check for low stock ingredients and send notifications';

    public function handle(NotificationService $notificationService)
    {
        $lowStockItems = InventoryStock::with('ingredient')
            ->whereColumn('current_quantity', '<=', 'reorder_point')
            ->get();
        
        $notifiedCount = 0;
        
        foreach ($lowStockItems as $stock) {
            if ($stock->ingredient && $stock->current_quantity > 0) {
                $notificationService->lowStockWarning(
                    $stock->ingredient,
                    $stock->current_quantity,
                    $stock->reorder_point
                );
                $notifiedCount++;
            } elseif ($stock->ingredient && $stock->current_quantity <= 0) {
                $notificationService->outOfStock($stock->ingredient);
                $notifiedCount++;
            }
        }
        
        $this->info("Notified about {$notifiedCount} low/out of stock items.");
        
        return Command::SUCCESS;
    }
}