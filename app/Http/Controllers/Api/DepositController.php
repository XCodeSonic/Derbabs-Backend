<?php

namespace App\Http\Controllers\Api;

use App\Services\DepositService;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    protected $depositService;
    
    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }
    
    public function pending()
    {
        return $this->ok($this->depositService->getPendingDeposits());
    }
    
    public function sendReminders()
    {
        $count = $this->depositService->sendDepositReminders();
        return $this->ok(['sent' => $count], "Deposit reminders sent to {$count} customers.");
    }
    
    public function autoCancel()
    {
        $result = $this->depositService->autoCancelUnpaidDeposits();
        return $this->ok($result, "Auto-cancelled {$result['count']} bookings.");
    }
}