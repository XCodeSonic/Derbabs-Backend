<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $booking;
    public $customerName;
    public $eventDate;
    public $eventTime;
    public $venue;
    public $totalAmount;
    public $bookingNo;
    public $bookingId;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
        $this->bookingNo = $booking->booking_no ?? 'N/A';
        $this->bookingId = $booking->booking_id;
        $this->customerName = $booking->serviceEvent?->customer?->person?->full_name ?? 'Customer';
        $this->eventDate = $booking->serviceEvent?->event_date?->format('F d, Y') ?? 'TBD';
        $this->eventTime = $booking->serviceEvent?->event_time ?? 'TBD';
        $this->venue = $booking->serviceEvent?->venue ?? 'TBD';
        $this->totalAmount = number_format($booking->quotation?->total_amount ?? 0, 2);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 Booking Confirmed - Dear Bab\'s Catering',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
            with: [
                'bookingNo' => $this->bookingNo,
                'bookingId' => $this->bookingId,
                'customerName' => $this->customerName,
                'eventDate' => $this->eventDate,
                'eventTime' => $this->eventTime,
                'venue' => $this->venue,
                'totalAmount' => $this->totalAmount,
            ],
        );
    }
}