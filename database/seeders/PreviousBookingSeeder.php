<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Booking;
use App\Models\ServiceEvent;
use App\Models\Quotation;
use App\Models\BookingItem;
use App\Models\BookingCharge;
use App\Models\EventDay;
use App\Models\MealService;
use App\Models\Customer;
use App\Models\Package;
use App\Models\MenuItem;
use App\Models\EventType;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;

class PreviousBookingSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();
        
        $customers = Customer::all();
        if ($customers->isEmpty()) {
            $this->command->warn('No customers found. Please run CustomerSeeder first.');
            return;
        }

        $packages = Package::all();
        $menuItems = MenuItem::all();
        $eventTypes = EventType::query()->pluck('event_type_id')->all();
        if ($eventTypes === [] || $menuItems->isEmpty()) {
            $this->command->warn('Historical bookings skipped: event types or menu items are missing.');
            return;
        }
        $mealTypes = ['breakfast', 'morning_snacks', 'lunch', 'afternoon_snacks', 'dinner'];
        $mealTimes = [
            'breakfast' => '07:00 AM',
            'morning_snacks' => '10:00 AM',
            'lunch' => '12:00 PM',
            'afternoon_snacks' => '03:00 PM',
            'dinner' => '06:00 PM',
        ];

        $venues = [
            'Manila Hotel', 'The Peninsula Manila', 'Shangri-La Makati', 'Sofitel Philippine Plaza',
            'Marriott Hotel Manila', 'Okada Manila', 'City of Dreams Manila', 'Resorts World Manila',
            'Solaire Resort & Casino', 'Conrad Manila', 'Seda Hotel', 'The Bellevue Manila',
            'Diamond Hotel', 'New World Hotel', 'Fairmont Hotel', 'Azure Urban Resort',
            'Rizal Park', 'Cultural Center of the Philippines', 'SMX Convention Center',
            'World Trade Center', 'Philippine International Convention Center', 'Mall of Asia Arena',
            'Araneta Coliseum', 'Cuneta Astrodome', 'Smart Araneta Coliseum', 'Meralco Theater'
        ];

        $streetNames = [
            'Makati Avenue', 'Ayala Avenue', 'Paseo de Roxas', 'Senator Gil Puyat Avenue',
            'Epifanio de los Santos Avenue', 'Commonwealth Avenue', 'Quezon Avenue',
            'Taft Avenue', 'Roxas Boulevard', 'Mabini Street', 'Luna Street',
            'Bonifacio Drive', 'Magsaysay Boulevard', 'Luneta Street', 'Bayani Road'
        ];

        $this->command->info('📊 Creating analytics-only historical completed bookings (HIST-BK-121 to HIST-BK-200)...');

        // Historical bookings - ALL COMPLETED (for dashboard history)
        for ($i = 121; $i <= 200; $i++) {
            $bookingNumber = 'HIST-BK-' . str_pad($i, 3, '0', STR_PAD_LEFT);
            if (Booking::withTrashed()->where('booking_no', $bookingNumber)->exists()) {
                continue;
            }

            DB::transaction(function () use (
                $faker,
                $customers,
                $packages,
                $menuItems,
                $eventTypes,
                $mealTypes,
                $mealTimes,
                $venues,
                $streetNames,
                $i,
                $bookingNumber
            ): void {
                $customer = $customers->random();
                $package = $packages->isNotEmpty() && $faker->boolean(60) ? $packages->random() : null;
            
            // Deterministically cover every month from January of the previous
            // year through the last completed month of the current year.
            $historyStart = Carbon::now()->subYear()->startOfYear();
            $historyEnd = Carbon::now()->subMonth()->endOfMonth();
            $monthCount = max(1, (int) $historyStart->diffInMonths($historyEnd) + 1);
            $monthOffset = ($i - 121) % $monthCount;
            $month = $historyStart->copy()->addMonths($monthOffset);
            $eventDate = $month->copy()->day(rand(1, min(25, $month->daysInMonth)));
            
            $isMultiDay = $faker->boolean(10);
            $days = $isMultiDay ? rand(2, 3) : 1;
            $endDate = $eventDate->copy()->addDays($days - 1);
            
            $guests = rand(15, 250);
            $menuSelectionType = $package ? 'package' : 'custom';
            $serviceTypes = ['buffet', 'packed', 'tray'];
            $serviceType = $serviceTypes[array_rand($serviceTypes)];
            $deliveryMethods = ['pickup', 'delivery'];
            $deliveryMethod = $deliveryMethods[array_rand($deliveryMethods)];
            
            $serviceEvent = ServiceEvent::create([
                'customer_id' => $customer->customer_id,
                'event_type_id' => $eventTypes[array_rand($eventTypes)],
                'package_id' => $package?->package_id,
                'event_date' => $eventDate->toDateString(),
                'event_end_date' => $endDate->toDateString(),
                'event_time' => $faker->randomElement(['08:00 AM', '09:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM', '06:00 PM']),
                'venue' => $venues[array_rand($venues)],
                'guests_count' => $guests,
                'service_type' => $serviceType,
                'menu_selection_type' => $menuSelectionType,
                'has_waiters' => $faker->boolean(60),
                'delivery_method' => $deliveryMethod,
                'special_requests' => $faker->boolean(30) ? $faker->sentence(10) : null,
                'delivery_address' => $deliveryMethod === 'delivery' ? rand(1, 200) . ' ' . $streetNames[array_rand($streetNames)] . ', ' . $faker->city . ', Metro Manila' : null,
                'delivery_contact_person' => $deliveryMethod === 'delivery' ? $faker->name : null,
                'delivery_contact_phone' => $deliveryMethod === 'delivery' ? '09' . rand(17, 18) . rand(100, 999) . rand(1000, 9999) : null,
                'delivery_fee' => $deliveryMethod === 'delivery' ? rand(200, 1500) : 0,
                'status' => 'completed',  // ✅ COMPLETED
                'booking_scope' => $isMultiDay ? 'multi_day' : 'regular',
                'created_at' => $eventDate->copy()->subDays(rand(14, 60)),
                'updated_at' => $eventDate->copy()->addDays(rand(1, 7)),
            ]);

            $baseAmount = 0;
            if ($package) {
                $baseAmount = $package->base_price_per_pax * $guests;
                if ($guests > $package->min_pax && $package->price_per_additional_pax > 0) {
                    $baseAmount += ($guests - $package->min_pax) * $package->price_per_additional_pax;
                }
            } else {
                $selectedItems = $menuItems->random(min(rand(3, 6), $menuItems->count()));
                foreach ($selectedItems as $item) {
                    $baseAmount += $item->price * rand(1, 3);
                }
                $baseAmount = $baseAmount * ($guests / 10);
            }

            $additionalCharges = rand(500, 3000);
            $totalAmount = $baseAmount + $additionalCharges;

            $quotation = Quotation::create([
                'quote_no' => 'HIST-QT-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'service_event_id' => $serviceEvent->service_event_id,
                'total_amount' => $totalAmount,
                'status' => 'approved',  // ✅ APPROVED
                'valid_until' => $eventDate->copy()->subDays(7)->toDateString(),
                'created_at' => $eventDate->copy()->subDays(rand(14, 40)),
                'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
            ]);

            $booking = Booking::create([
                'booking_no' => $bookingNumber,
                'service_event_id' => $serviceEvent->service_event_id,
                'quotation_id' => $quotation->quotation_id,
                'required_deposit' => $totalAmount * 0.3,
                'booking_status' => 'completed',  // ✅ COMPLETED
                'requested_date' => $eventDate->toDateString(),
                'requested_time' => $serviceEvent->event_time,
                'created_at' => $eventDate->copy()->subDays(rand(14, 60)),
                'updated_at' => $eventDate->copy()->addDays(rand(1, 7)),
            ]);

            if ($additionalCharges > 0) {
                BookingCharge::create([
                    'booking_id' => $booking->booking_id,
                    'charge_kind' => 'charge',
                    'charge_type' => 'additional_charges',
                    'description' => 'Additional service charges',
                    'amount' => $additionalCharges,
                    'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                    'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                ]);
            }

            if ($faker->boolean(20)) {
                $discount = rand(100, 1000);
                BookingCharge::create([
                    'booking_id' => $booking->booking_id,
                    'charge_kind' => 'discount',
                    'charge_type' => 'promo_discount',
                    'description' => 'Promotional discount applied',
                    'amount' => $discount,
                    'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                    'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                ]);
            }

            $eventDays = [];
            if ($isMultiDay) {
                for ($day = 1; $day <= $days; $day++) {
                    $dayDate = $eventDate->copy()->addDays($day - 1);
                    $eventDay = EventDay::create([
                        'booking_id' => $booking->booking_id,
                        'day_number' => $day,
                        'date' => $dayDate->toDateString(),
                        'day_status' => 'completed',
                        'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                        'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                    ]);
                    $eventDays[] = $eventDay;
                }
            } else {
                $eventDay = EventDay::create([
                    'booking_id' => $booking->booking_id,
                    'day_number' => 1,
                    'date' => $eventDate->toDateString(),
                    'day_status' => 'completed',
                    'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                    'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                ]);
                $eventDays[] = $eventDay;
            }

            $mealCount = $faker->numberBetween(2, 4);
            $selectedMealTypes = $faker->randomElements($mealTypes, $mealCount);
            
            foreach ($selectedMealTypes as $mealType) {
                $dayIndex = array_rand($eventDays);
                $eventDay = $eventDays[$dayIndex];
                $pax = rand(10, $guests);
                $menuItem = $menuItems->random();
                $pricePerHead = $menuItem->price * rand(1, 3) / 5;
                
                $mealService = MealService::create([
                    'booking_id' => $booking->booking_id,
                    'event_day_id' => $eventDay->event_day_id,
                    'meal_type' => $mealType,
                    'serving_time' => $mealTimes[$mealType] ?? '12:00 PM',
                    'preparation_time' => Carbon::parse($mealTimes[$mealType] ?? '12:00 PM')->subHours(2)->format('h:i A'),
                    'dispatch_time' => Carbon::parse($mealTimes[$mealType] ?? '12:00 PM')->subMinutes(30)->format('h:i A'),
                    'arrival_time' => $mealTimes[$mealType] ?? '12:00 PM',
                    'pax' => $pax,
                    'menu_source' => 'custom',
                    'menu_item_id' => $menuItem->menu_item_id,
                    'price_per_head' => $pricePerHead,
                    'preparation_status' => 'completed',
                    'delivery_status' => 'completed',
                    'serving_status' => 'completed',
                    'meal_status' => 'completed',
                    'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                    'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                ]);

                BookingItem::create([
                    'booking_id' => $booking->booking_id,
                    'menu_item_id' => $menuItem->menu_item_id,
                    'meal_service_id' => $mealService->meal_service_id,
                    'custom_item_name' => null,
                    'description' => $menuItem->description,
                    'quantity' => $pax,
                    'unit_price' => $menuItem->price,
                    'item_type' => 'menu_item',
                    'action_type' => 'included',
                    'special_instructions' => $faker->boolean(20) ? $faker->sentence(5) : null,
                    'created_at' => $eventDate->copy()->subDays(rand(7, 30)),
                    'updated_at' => $eventDate->copy()->subDays(rand(7, 20)),
                ]);
            }
            });
        }

        $this->command->info('✅ Analytics-only historical bookings are ready: HIST-BK-121 to HIST-BK-200');
    }
}