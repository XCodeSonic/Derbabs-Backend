<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\QuotationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBookingApprovalSideEffects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $bookingId;
    public $orderId;
    public $shouldSendQuotation;

    public function __construct($bookingId, $orderId, $shouldSendQuotation)
    {
        $this->bookingId = $bookingId;
        $this->orderId = $orderId;
        $this->shouldSendQuotation = $shouldSendQuotation;
    }

    public function handle()
    {
        $freshBooking = Booking::with(['serviceEvent.customer.person', 'quotation'])->find($this->bookingId);
        if (!$freshBooking) return;

        // 1. Send Quotation
        if ($this->shouldSendQuotation && $freshBooking->quotation) {
            try {
                app(QuotationDeliveryService::class)->send($freshBooking->quotation);
            } catch (\Throwable $e) {
                Log::warning('Quotation send failed in job: ' . $e->getMessage());
            }
        }

        // 2. Send Email & Notifications
        try {
            // Move your sendBookingConfirmation logic here
            // app(NotificationService::class)->notifyUser(...);
        } catch (\Throwable $e) {
            Log::warning('Notification failed in job: ' . $e->getMessage());
        }

        // 3. Dispatch other heavy jobs (Kitchen, Delivery, etc.)
        if ($this->orderId) {
            $freshOrder = Order::find($this->orderId);
            if ($freshOrder) {
                CreateKitchenPreparationJob::dispatch($freshBooking, $freshOrder);
                CreateDeliveryPreparationJob::dispatch($freshBooking, $freshOrder);
            }
        }
        CreateIngredientsManagementJob::dispatch($freshBooking);
        CreateEventTrackingJob::dispatch($freshBooking);
    }
}