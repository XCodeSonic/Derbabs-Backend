<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EventType;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\ServiceEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HistoricalDashboardSeeder extends Seeder
{
    /**
     * Fills the remaining gaps so every dashboard chart has real data
     * for the last 18 months.
     *
     *   - extends bookings back to 18 months (mix of completed/confirmed/ongoing)
     *   - creates invoices + completed payments on each booking
     *   - creates purchase + waste inventory movements across the whole period
     *   - keeps everything idempotent: safe to rerun
     */
    public function run(): void
    {
        $customers = Customer::all();
        $packages  = Package::all();
        $employees = Employee::orderBy('employee_id')->get();
        $ingredients = Ingredient::where('is_active', true)->get();
        $eventTypes = EventType::pluck('event_type_id')->all();
        $userId = User::query()->value('user_id');

        if ($customers->isEmpty() || $eventTypes === []) {
            $this->command?->warn('HistoricalDashboardSeeder: customers or event types missing.');
            return;
        }

        // ── How far back? 18 months covers Yearly view nicely.
        $monthsBack = 18;

        $this->command?->info("Creating historical bookings/payments/expenses for the last {$monthsBack} months...");

        for ($m = $monthsBack; $m >= 0; $m--) {
            $monthStart = now()->subMonthsNoOverflow($m)->startOfMonth();
            $monthEnd   = $monthStart->copy()->endOfMonth();

            // How many bookings for this month? Fewer for very old months.
            $bookingsThisMonth = $m === 0 ? 4 : rand(6, 12);

            for ($b = 0; $b < $bookingsThisMonth; $b++) {
                $eventDate = Carbon::create(
                    $monthStart->year,
                    $monthStart->month,
                    rand(1, (int) $monthStart->daysInMonth)
                );

                // Status by age:
                //   > 60 days old  → completed
                //   15–60 days old → ongoing/confirmed
                //   < 15 days old  → confirmed
                $ageDays = (int) now()->diffInDays($eventDate, false);
                $status = match (true) {
                    $ageDays > 60  => 'completed',
                    $ageDays > 15  => 'confirmed',
                    default        => 'confirmed',
                };

                $this->seedOneBooking(
                    $customers, $packages, $eventTypes, $userId,
                    $eventDate, $status
                );
            }

            // ── Inventory: purchase + waste movements every month ──
            if ($ingredients->isNotEmpty() && $userId) {
                $this->seedInventoryMonth($ingredients, $userId, $monthStart, $monthEnd);
            }
        }

        $this->command?->info('HistoricalDashboardSeeder finished.');
    }

    private function seedOneBooking(
        $customers, $packages, array $eventTypes, ?int $userId,
        Carbon $eventDate, string $status
    ): void {
        $bookingNo = 'HIST-DASH-' . $eventDate->format('Ym') . '-' . rand(1000, 9999);

        // Idempotency — skip if this exact booking_no already exists.
        if (Booking::where('booking_no', $bookingNo)->exists()) {
            return;
        }

        $customer = $customers->random();
        $package  = $packages->isNotEmpty() && rand(0, 100) < 60 ? $packages->random() : null;
        $guests   = rand(30, 250);
        $pax      = $guests;

        $baseAmount = $package
            ? (float) $package->base_price_per_pax * $guests
            : rand(15000, 80000);

        $totalAmount = round($baseAmount + rand(500, 5000), 2);
        $createdAt   = $eventDate->copy()->subDays(rand(25, 45));
        $updatedAt   = $status === 'completed'
            ? $eventDate->copy()->addDays(2)
            : $eventDate->copy()->subDays(3);

        DB::transaction(function () use (
            $bookingNo, $customer, $package, $eventTypes, $eventDate,
            $guests, $totalAmount, $status, $createdAt, $updatedAt
        ) {
            $serviceEvent = ServiceEvent::create([
                'customer_id'             => $customer->customer_id,
                'event_type_id'           => $eventTypes[array_rand($eventTypes)],
                'package_id'              => $package?->package_id,
                'event_date'              => $eventDate->toDateString(),
                'event_end_date'          => $eventDate->toDateString(),
                'event_time'              => '12:00 PM',
                'venue'                   => 'Historical Venue ' . $eventDate->format('M Y'),
                'guests_count'            => $guests,
                'service_type'            => 'buffet',
                'menu_selection_type'     => $package ? 'package' : 'custom',
                'has_waiters'             => true,
                'delivery_method'         => 'delivery',
                'status'                  => $status,
                'created_at'              => $createdAt,
                'updated_at'              => $updatedAt,
            ]);

            $quotation = \App\Models\Quotation::create([
                'quote_no'         => 'QTH-' . $bookingNo,
                'service_event_id' => $serviceEvent->service_event_id,
                'total_amount'     => $totalAmount,
                'status'           => 'approved',
                'valid_until'      => $eventDate->copy()->subDays(7)->toDateString(),
                'created_at'       => $createdAt,
                'updated_at'       => $createdAt,
            ]);

            $booking = Booking::create([
                'booking_no'        => $bookingNo,
                'service_event_id'  => $serviceEvent->service_event_id,
                'quotation_id'      => $quotation->quotation_id,
                'required_deposit'  => round($totalAmount * 0.3, 2),
                'booking_status'    => $status,
                'requested_date'    => $eventDate->toDateString(),
                'requested_time'    => '12:00 PM',
                'created_at'        => $createdAt,
                'updated_at'        => $updatedAt,
            ]);

            // Invoice
            $invoice = Invoice::create([
                'invoice_number' => 'INV-HIST-' . $booking->booking_id,
                'booking_id'     => $booking->booking_id,
                'subtotal'       => $totalAmount,
                'total_amount'   => $totalAmount,
                'paid_amount'    => 0,
                'status'         => 'unpaid',
                'due_date'       => $eventDate->copy()->subDays(3)->toDateString(),
                'created_at'     => $createdAt,
                'updated_at'     => $createdAt,
            ]);

            // Payments — split total into 1–3 transactions spread before event date.
            $remaining = $totalAmount;
            $paymentCount = rand(1, 3);
            for ($i = 0; $i < $paymentCount; $i++) {
                $isLast  = $i === $paymentCount - 1;
                $amount  = $isLast
                    ? $remaining
                    : round($remaining * (rand(30, 60) / 100), 2);
                if ($amount <= 0) break;
                $remaining -= $amount;

                $paymentDate = $eventDate->copy()->subDays(rand(1, 30));

                BookingPayment::create([
                    'payment_number'   => 'PAY-HIST-' . $booking->booking_id . '-' . ($i + 1),
                    'booking_id'       => $booking->booking_id,
                    'amount'           => $amount,
                    'payment_method'   => ['cash', 'gcash', 'bank_transfer'][array_rand(['cash', 'gcash', 'bank_transfer'])],
                    'payment_type'     => $i === 0 ? 'deposit' : ($isLast ? 'full' : 'partial'),
                    'reference_number' => 'HREF-' . strtoupper(bin2hex(random_bytes(3))),
                    'status'           => 'completed',
                    'payment_date'     => $paymentDate,
                    'verified_by'      => \App\Models\User::query()->value('user_id'),
                    'verified_at'      => $paymentDate->copy()->addHours(2),
                    'created_at'       => $paymentDate,
                    'updated_at'       => $paymentDate,
                ]);
            }

            $invoice->update([
                'paid_amount' => $totalAmount - max(0, $remaining),
                'status'      => $remaining <= 0 ? 'paid' : 'partial',
            ]);
        });
    }

    private function seedInventoryMonth($ingredients, int $userId, Carbon $monthStart, Carbon $monthEnd): void
    {
        // 6–12 purchase movements spread across the month
        $purchaseCount = rand(6, 12);
        for ($i = 0; $i < $purchaseCount; $i++) {
            $ingredient = $ingredients->random();
            $day = rand(1, (int) $monthStart->daysInMonth);
            $at  = Carbon::create($monthStart->year, $monthStart->month, $day, rand(8, 17));
            $qty = rand(20, 120);

            InventoryMovement::create([
                'ingredient_id'      => $ingredient->ingredient_id,
                'performed_by'       => $userId,
                'movement_type'      => 'purchase',
                'quantity_change'    => $qty,
                'quantity_before'    => 100,
                'quantity_after'     => 100 + $qty,
                'unit_cost_at_time'  => (float) ($ingredient->unit_cost ?? 50),
                'reason'             => 'Historical restock',
                'reference_type'     => 'HistoricalDashboard',
                'reference_id'       => null,
                'created_at'         => $at,
                'updated_at'         => $at,
            ]);
        }

        // 2–5 waste movements
        $wasteCount = rand(2, 5);
        for ($i = 0; $i < $wasteCount; $i++) {
            $ingredient = $ingredients->random();
            $day = rand(1, (int) $monthStart->daysInMonth);
            $at  = Carbon::create($monthStart->year, $monthStart->month, $day, rand(8, 17));
            $qty = rand(1, 8);

            InventoryMovement::create([
                'ingredient_id'      => $ingredient->ingredient_id,
                'performed_by'       => $userId,
                'movement_type'      => 'waste',
                'quantity_change'    => -$qty,
                'quantity_before'    => 100,
                'quantity_after'     => 100 - $qty,
                'unit_cost_at_time'  => (float) ($ingredient->unit_cost ?? 50),
                'reason'             => 'Historical spoilage',
                'reference_type'     => 'HistoricalDashboard',
                'reference_id'       => null,
                'created_at'         => $at,
                'updated_at'         => $at,
            ]);
        }
    }
}