<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BookingPayment;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Faker\Factory as Faker;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();
        $verifierId = User::query()->value('user_id');
        
        $completedBookings = Booking::where('booking_status', 'completed')->get();
        
        if ($completedBookings->isEmpty()) {
            $this->command->warn('No completed bookings found.');
            return;
        }

        $this->command->info('💰 Creating payments for completed bookings...');

        $paymentMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];
        $paymentTypes = ['deposit', 'partial', 'full'];

        foreach ($completedBookings as $booking) {
            $invoice = Invoice::where('booking_id', $booking->booking_id)->first();
            if (BookingPayment::where('booking_id', $booking->booking_id)->exists()) {
                if ($invoice) {
                    $existingPaid = BookingPayment::where('booking_id', $booking->booking_id)
                        ->where('status', 'completed')
                        ->where('payment_type', '!=', 'refund')
                        ->sum('amount');
                    $invoice->update([
                        'paid_amount' => $existingPaid,
                        'status' => $existingPaid >= $invoice->total_amount ? 'paid' : ($existingPaid > 0 ? 'partial' : 'unpaid'),
                    ]);
                }
                continue;
            }
            $totalAmount = $invoice?->total_amount ?? $booking->quotation?->total_amount ?? rand(5000, 50000);
            
            // Create 1-3 payments per booking
            $numPayments = rand(1, 3);
            $remainingAmount = $totalAmount;
            
            for ($i = 0; $i < $numPayments; $i++) {
                $isLast = ($i === $numPayments - 1);
                $amount = $isLast ? $remainingAmount : rand(round($totalAmount * 0.2), round($totalAmount * 0.6));
                $amount = min($amount, $remainingAmount);
                $remainingAmount -= $amount;
                
                if ($amount <= 0) break;
                
                $paymentDate = Carbon::parse($booking->serviceEvent?->event_date ?? now())
                    ->subDays(rand(1, 30));
                
                // Determine payment type
                $paymentType = $i === 0 ? 'deposit' : ($isLast ? 'full' : 'partial');
                
                BookingPayment::create([
                    'payment_number' => 'SEED-PAY-' . $booking->booking_id . '-' . ($i + 1),
                    'booking_id' => $booking->booking_id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                    'payment_type' => $paymentType,
                    'reference_number' => 'REF-' . strtoupper($faker->bothify('???####')),
                    'status' => 'completed',
                    'payment_date' => $paymentDate,
                    'verified_by' => $verifierId,
                    'verified_at' => $paymentDate->copy()->addHours(rand(1, 24)),
                    'created_at' => $paymentDate,
                    'updated_at' => $paymentDate,
                ]);
            }
            
            // Update invoice paid amount
            if ($invoice) {
                $totalPaid = BookingPayment::where('booking_id', $booking->booking_id)
                    ->where('status', 'completed')
                    ->where('payment_type', '!=', 'refund')
                    ->sum('amount');
                
                $invoice->update([
                    'paid_amount' => $totalPaid,
                    'status' => $totalPaid >= $invoice->total_amount ? 'paid' : 'partial',
                ]);
            }
        }

        $this->command->info('✅ Payments created successfully!');
    }
}