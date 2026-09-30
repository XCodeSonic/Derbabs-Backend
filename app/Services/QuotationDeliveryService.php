<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\Quotation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class QuotationDeliveryService
{
    /**
     * Send an existing quotation without creating another quotation record.
     */
    public function send(Quotation $quotation): array
    {
        $quotation->loadMissing(['serviceEvent.customer.person', 'serviceEvent.eventType']);

        $event = $quotation->serviceEvent;
        $customer = $event?->customer;
        $person = $customer?->person;
        $email = $person?->email;
        $name = trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')) ?: 'Customer';
        $eventName = $event?->eventType?->name ?? 'your event';
        $amount = number_format((float) $quotation->total_amount, 2);

        $message = "Hello {$name},

Your quotation {$quotation->quote_no} for {$eventName} has been generated. Total amount: ₱{$amount}. Valid until: " . optional($quotation->valid_until)->format('F d, Y') . ".

Thank you,
Dear Bab's Catering";

        $emailStatus = $email ? 'sent' : 'no_email';
        $messengerStatus = $customer?->user_id ? 'pending' : 'not_connected';

        if ($email) {
            try {
                Mail::raw($message, function ($mail) use ($email, $quotation) {
                    $mail->to($email)->subject("Dear Bab's Catering Quotation {$quotation->quote_no}");
                });
            } catch (\Throwable $e) {
                $emailStatus = 'failed';
                Log::warning('Quotation email delivery failed: ' . $e->getMessage(), [
                    'quotation_id' => $quotation->quotation_id,
                ]);
            }
        }

        // The internal mobile Messenger is considered connected only when the
        // customer has a linked application user account.
        if ($customer?->customer_id && $customer?->user_id) {
            try {
                $thread = ChatThread::query()->firstOrCreate(
                    ['customer_id' => $customer->customer_id, 'status' => 'open'],
                    ['assigned_user_id' => null]
                );

                ChatMessage::query()->create([
                    'thread_id' => $thread->thread_id,
                    'sender_user_id' => null,
                    'message' => $message,
                ]);

                $messengerStatus = 'sent';
            } catch (\Throwable $e) {
                $messengerStatus = 'failed';
                Log::warning('Quotation Messenger delivery failed: ' . $e->getMessage(), [
                    'quotation_id' => $quotation->quotation_id,
                ]);
            }
        }

        $deliveryStatus = ($emailStatus === 'sent' || $messengerStatus === 'sent') ? 'Sent' : 'Failed';

        if ($customer?->user_id) {
            Notification::query()->create([
                'user_id' => $customer->user_id,
                'type' => 'quotation_sent',
                'priority' => Notification::PRIORITY_HIGH,
                'title' => 'Quotation Sent',
                'message' => $message,
                'data' => [
                    'quotation_id' => $quotation->quotation_id,
                    'quote_no' => $quotation->quote_no,
                    'gmail_delivery_status' => $emailStatus,
                    'messenger_delivery_status' => $messengerStatus,
                    'delivery_status' => $deliveryStatus,
                ],
                'is_sent' => $deliveryStatus === 'Sent',
                'sent_at' => now(),
            ]);
        }

        return [
            'delivery_status' => $deliveryStatus,
            'gmail_delivery_status' => $emailStatus,
            'messenger_delivery_status' => $messengerStatus,
        ];
    }
}
