<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyDueBalances extends Command
{
    protected $signature = 'notify:due-balances';
    protected $description = 'Send reminders for due and overdue balances';

    public function handle(NotificationService $notificationService)
    {
        $invoices = Invoice::whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<=', Carbon::today()->addDays(3))
            ->with('booking.serviceEvent.customer')
            ->get();
        
        $notifiedCount = 0;
        
        foreach ($invoices as $invoice) {
            $customer = $invoice->booking?->serviceEvent?->customer;
            if ($customer && $invoice->balance > 0) {
                $daysLeft = Carbon::today()->diffInDays($invoice->due_date, false);
                
                if ($daysLeft <= 3 && $daysLeft >= 0) {
                    $notificationService->balanceDueReminder($invoice, $customer, $daysLeft);
                    $notifiedCount++;
                }
                
                if ($invoice->due_date->isPast()) {
                    $notificationService->overdueAccountAlert($customer, $invoice->balance, abs($daysLeft));
                    $notifiedCount++;
                }
            }
        }
        
        $this->info("Sent balance reminders for {$notifiedCount} invoices.");
        
        return Command::SUCCESS;
    }
}