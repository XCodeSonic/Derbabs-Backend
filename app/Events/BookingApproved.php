<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function broadcastOn()
    {
        return new Channel('bookings');
    }

    public function broadcastAs()
    {
        return 'booking.approved';
    }

    public function broadcastWith()
    {
        return [
            'booking_id' => $this->booking->booking_id,
            'booking_no' => $this->booking->booking_no,
            'status' => $this->booking->booking_status,
            'customer_name' => $this->booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
        ];
    }
}
