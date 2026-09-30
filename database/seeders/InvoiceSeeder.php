<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Invoice;
use App\Models\Booking;
use Carbon\Carbon;
use Faker\Factory as Faker;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();
        
        // Get completed bookings
        $completedBookings = Booking::where('booking_status', 'completed')->get();
        
        if ($completedBookings->isEmpty()) {
            $this->command->warn('No completed bookings found. Please run BookingSeeder first.');
            return;
        }

        $this->command->info('📊 Creating invoices for completed bookings...');

        foreach ($completedBookings as $booking) {
            if (Invoice::where('booking_id', $booking->booking_id)->exists()) {
                continue;
            }

            $subtotal = max(1, (float) ($booking->quotation?->total_amount ?? rand(5000, 50000)));
            $discount = (float) rand(0, min(1000, (int) floor($subtotal * 0.1)));
            $additionalCharges = (float) rand(0, 2000);
            $totalAmount = max(1, $subtotal - $discount + $additionalCharges);
            $paidAmount = 0;
            $status = 'unpaid';
            
            // Random due date (30-60 days after event)
            $eventDate = $booking->serviceEvent?->event_date ?? Carbon::now()->subDays(rand(1, 30));
            $dueDate = Carbon::parse($eventDate)->addDays(rand(30, 60));
            
            Invoice::create([
                'invoice_number' => 'INV-' . str_pad($booking->booking_id, 3, '0', STR_PAD_LEFT),
                'booking_id' => $booking->booking_id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'discount_type' => 'fixed',
                'additional_charges' => $additionalCharges,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'status' => $status,
                'due_date' => $dueDate,
                'created_at' => Carbon::parse($eventDate)->subDays(rand(7, 14)),
                'updated_at' => Carbon::parse($eventDate)->subDays(rand(1, 5)),
            ]);
        }

        $this->command->info('✅ Invoices created successfully!');
    }
}