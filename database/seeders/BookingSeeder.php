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
use App\Models\MealServiceCustomItem;
use App\Models\Customer;
use App\Models\Package;
use App\Models\MenuItem;
use Carbon\Carbon;
use Faker\Factory as Faker;

class BookingSeeder extends Seeder
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
        $eventTypes = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
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
            'Araneta Coliseum', 'Cuneta Astrodome', 'Smart Araneta Coliseum', 'Meralco Theater',
            'Carlos P. Garcia Garden', 'Quezon Memorial Circle', 'Eton Centris', 'Rockwell Center'
        ];

        $streetNames = [
            'Makati Avenue', 'Ayala Avenue', 'Paseo de Roxas', 'Senator Gil Puyat Avenue',
            'Epifanio de los Santos Avenue', 'Commonwealth Avenue', 'Quezon Avenue',
            'Taft Avenue', 'Roxas Boulevard', 'Mabini Street', 'Luna Street',
            'Bonifacio Drive', 'Magsaysay Boulevard', 'Luneta Street', 'Bayani Road'
        ];

        $this->command->info('📊 Seeding bookings...');

        // ============================================================
        // PART 1: PENDING BOOKINGS (Show in Booking Table)
        // ============================================================
        $this->command->info('📝 Creating 80 PENDING bookings (BK-001 to BK-080)...');
        
        for ($i = 1; $i <= 80; $i++) {
            $customer = $customers->random();
            $package = $faker->boolean(70) ? $packages->random() : null;
            
            $eventDate = Carbon::now()->addDays(rand(1, 180));
            
            $isMultiDay = $faker->boolean(20);
            $days = $isMultiDay ? rand(2, 5) : 1;
            $endDate = $eventDate->copy()->addDays($days - 1);
            
            $guests = rand(20, 300);
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
                'status' => 'pending',  // ✅ PENDING status
                'booking_scope' => $isMultiDay ? 'multi_day' : 'regular',
            ]);

            $baseAmount = 0;
            if ($package) {
                $baseAmount = $package->base_price_per_pax * $guests;
                if ($guests > $package->min_pax && $package->price_per_additional_pax > 0) {
                    $baseAmount += ($guests - $package->min_pax) * $package->price_per_additional_pax;
                }
            } else {
                $selectedItems = $menuItems->random(min(rand(3, 8), $menuItems->count()));
                foreach ($selectedItems as $item) {
                    $baseAmount += $item->price * rand(1, 3);
                }
                $baseAmount = $baseAmount * ($guests / 10);
            }

            $additionalCharges = rand(500, 3000);
            $totalAmount = $baseAmount + $additionalCharges;

            $bookingNumber = 'BK-' . str_pad($i, 3, '0', STR_PAD_LEFT);

            $quotation = Quotation::create([
                'quote_no' => 'QT-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'service_event_id' => $serviceEvent->service_event_id,
                'total_amount' => $totalAmount,
                'status' => 'pending',  // ✅ PENDING quotation
                'valid_until' => $eventDate->copy()->subDays(7)->toDateString(),
            ]);

            $booking = Booking::create([
                'booking_no' => $bookingNumber,
                'service_event_id' => $serviceEvent->service_event_id,
                'quotation_id' => $quotation->quotation_id,
                'required_deposit' => $totalAmount * 0.3,
                'booking_status' => 'pending_approval',  // ✅ PENDING APPROVAL
                'requested_date' => $eventDate->toDateString(),
                'requested_time' => $serviceEvent->event_time,
            ]);

            // Add charges and meals (same as before)
            if ($additionalCharges > 0) {
                BookingCharge::create([
                    'booking_id' => $booking->booking_id,
                    'charge_kind' => 'charge',
                    'charge_type' => 'additional_charges',
                    'description' => 'Additional service charges',
                    'amount' => $additionalCharges,
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
                ]);
            }

            // Event Days
            $eventDays = [];
            if ($isMultiDay) {
                for ($day = 1; $day <= $days; $day++) {
                    $dayDate = $eventDate->copy()->addDays($day - 1);
                    $eventDay = EventDay::create([
                        'booking_id' => $booking->booking_id,
                        'day_number' => $day,
                        'date' => $dayDate->toDateString(),
                        'day_status' => 'pending',
                    ]);
                    $eventDays[] = $eventDay;
                }
            } else {
                $eventDay = EventDay::create([
                    'booking_id' => $booking->booking_id,
                    'day_number' => 1,
                    'date' => $eventDate->toDateString(),
                    'day_status' => 'pending',
                ]);
                $eventDays[] = $eventDay;
            }

            // Meal Services
            $mealCount = $faker->numberBetween(2, 5);
            $selectedMealTypes = $faker->randomElements($mealTypes, $mealCount);
            
            foreach ($selectedMealTypes as $mealType) {
                $dayIndex = array_rand($eventDays);
                $eventDay = $eventDays[$dayIndex];
                
                $pax = rand(10, $guests);
                $usePackage = $package && $faker->boolean(60);
                $menuItem = $menuItems->random();
                $pricePerHead = $usePackage ? 
                    ($package->base_price_per_pax / count($selectedMealTypes)) : 
                    $menuItem->price * rand(1, 3) / 5;
                
                $mealService = MealService::create([
                    'booking_id' => $booking->booking_id,
                    'event_day_id' => $eventDay->event_day_id,
                    'meal_type' => $mealType,
                    'serving_time' => $mealTimes[$mealType] ?? '12:00 PM',
                    'preparation_time' => Carbon::parse($mealTimes[$mealType] ?? '12:00 PM')->subHours(2)->format('h:i A'),
                    'dispatch_time' => Carbon::parse($mealTimes[$mealType] ?? '12:00 PM')->subMinutes(30)->format('h:i A'),
                    'arrival_time' => $mealTimes[$mealType] ?? '12:00 PM',
                    'pax' => $pax,
                    'menu_source' => $usePackage ? 'package' : 'custom',
                    'package_id' => $usePackage ? $package->package_id : null,
                    'menu_item_id' => $usePackage ? null : $menuItem->menu_item_id,
                    'price_per_head' => $pricePerHead,
                    'notes' => $faker->boolean(30) ? $faker->sentence(5) : null,
                    'preparation_status' => 'pending',
                    'delivery_status' => 'pending',
                    'serving_status' => 'pending',
                    'meal_status' => 'pending',
                ]);

                if (!$usePackage && $faker->boolean(40)) {
                    $extraItems = $menuItems->random(min(rand(1, 3), $menuItems->count()));
                    foreach ($extraItems as $item) {
                        MealServiceCustomItem::create([
                            'meal_service_id' => $mealService->meal_service_id,
                            'menu_item_id' => $item->menu_item_id,
                            'item_name' => $item->name,
                            'description' => $item->description,
                            'quantity' => rand(1, 5),
                            'unit_price' => $item->price * 0.8,
                            'notes' => $faker->sentence(5),
                        ]);
                    }
                }

                if ($usePackage && $package) {
                    $packageItems = $package->menuItems()->limit(rand(2, 5))->get();
                    foreach ($packageItems as $pkgItem) {
                        BookingItem::create([
                            'booking_id' => $booking->booking_id,
                            'menu_item_id' => $pkgItem->menu_item_id,
                            'meal_service_id' => $mealService->meal_service_id,
                            'custom_item_name' => null,
                            'description' => $pkgItem->description,
                            'quantity' => $pax,
                            'unit_price' => $pkgItem->price,
                            'item_type' => 'menu_item',
                            'action_type' => 'included',
                            'special_instructions' => $faker->boolean(20) ? $faker->sentence(5) : null,
                        ]);
                    }
                } else {
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
                    ]);
                }
            }

            if ($faker->boolean(30)) {
                $extraItems = $menuItems->random(min(rand(1, 3), $menuItems->count()));
                foreach ($extraItems as $item) {
                    BookingItem::create([
                        'booking_id' => $booking->booking_id,
                        'menu_item_id' => $item->menu_item_id,
                        'meal_service_id' => null,
                        'custom_item_name' => null,
                        'description' => 'Additional item',
                        'quantity' => rand(1, 5),
                        'unit_price' => $item->price,
                        'item_type' => 'menu_item',
                        'action_type' => 'added',
                        'special_instructions' => $faker->sentence(5),
                    ]);
                }
            }
        }

        // ============================================================
        // PART 2: COMPLETED BOOKINGS (NOT in Booking Table)
        // These will ONLY appear in history/reports
        // ============================================================
        $this->command->info('✅ Creating 40 COMPLETED bookings (BK-081 to BK-120)...');
        
        for ($i = 81; $i <= 120; $i++) {
            $customer = $customers->random();
            $package = $faker->boolean(60) ? $packages->random() : null;
            
            // ✅ COMPLETED BOOKINGS - Past dates only
            $monthsAgo = rand(1, 6);
            $eventDate = Carbon::now()->subMonths($monthsAgo)->subDays(rand(1, 20));
            
            $isMultiDay = $faker->boolean(10);
            $days = $isMultiDay ? rand(2, 3) : 1;
            $endDate = $eventDate->copy()->addDays($days - 1);
            
            $guests = rand(20, 250);
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
                'status' => 'completed',  // ✅ COMPLETED status
                'booking_scope' => $isMultiDay ? 'multi_day' : 'regular',
                'created_at' => $eventDate->copy()->subDays(rand(20, 40)),
                'updated_at' => $eventDate->copy()->addDays(rand(1, 5)),
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

            $additionalCharges = rand(300, 2000);
            $totalAmount = $baseAmount + $additionalCharges;

            $bookingNumber = 'BK-' . str_pad($i, 3, '0', STR_PAD_LEFT);

            $quotation = Quotation::create([
                'quote_no' => 'QT-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'service_event_id' => $serviceEvent->service_event_id,
                'total_amount' => $totalAmount,
                'status' => 'approved',  // ✅ APPROVED quotation
                'valid_until' => $eventDate->copy()->subDays(7)->toDateString(),
                'created_at' => $eventDate->copy()->subDays(20),
                'updated_at' => $eventDate->copy()->subDays(10),
            ]);

            $booking = Booking::create([
                'booking_no' => $bookingNumber,
                'service_event_id' => $serviceEvent->service_event_id,
                'quotation_id' => $quotation->quotation_id,
                'required_deposit' => $totalAmount * 0.3,
                'booking_status' => 'completed',  // ✅ COMPLETED status
                'requested_date' => $eventDate->toDateString(),
                'requested_time' => $serviceEvent->event_time,
                'created_at' => $eventDate->copy()->subDays(25),
                'updated_at' => $eventDate->copy()->addDays(3),
            ]);

            if ($additionalCharges > 0) {
                BookingCharge::create([
                    'booking_id' => $booking->booking_id,
                    'charge_kind' => 'charge',
                    'charge_type' => 'additional_charges',
                    'description' => 'Additional service charges',
                    'amount' => $additionalCharges,
                    'created_at' => $eventDate->copy()->subDays(20),
                    'updated_at' => $eventDate->copy()->subDays(10),
                ]);
            }

            // Simple meal services for completed bookings
            $eventDay = EventDay::create([
                'booking_id' => $booking->booking_id,
                'day_number' => 1,
                'date' => $eventDate->toDateString(),
                'day_status' => 'completed',
                'created_at' => $eventDate->copy()->subDays(20),
                'updated_at' => $eventDate->copy()->subDays(10),
            ]);

            $mealCount = rand(2, 4);
            $selectedMealTypes = $faker->randomElements($mealTypes, $mealCount);
            
            foreach ($selectedMealTypes as $mealType) {
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
                    'created_at' => $eventDate->copy()->subDays(20),
                    'updated_at' => $eventDate->copy()->subDays(5),
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
                    'created_at' => $eventDate->copy()->subDays(20),
                    'updated_at' => $eventDate->copy()->subDays(10),
                ]);
            }
        }

        $this->command->info('✅ Seeding complete!');
        $this->command->info('📊 PENDING bookings: BK-001 to BK-080 (80 bookings)');
        $this->command->info('📊 COMPLETED bookings: BK-081 to BK-120 (40 bookings)');
        $this->command->info('📊 Total bookings: 120');
    }
}