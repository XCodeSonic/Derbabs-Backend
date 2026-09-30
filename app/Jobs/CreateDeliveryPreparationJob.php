<?php

// app/Jobs/CreateDeliveryPreparationJob.php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Order;
use App\Services\BookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateDeliveryPreparationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;
    protected $order;

    public function __construct(Booking $booking, ?Order $order = null)
    {
        $this->booking = $booking;
        $this->order = $order;
    }

    public function handle(BookingService $service)
    {
        if ($this->order) {
            $service->createDeliveryPreparation($this->booking, $this->order);
        }
    }
}
