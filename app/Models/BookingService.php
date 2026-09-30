<?php

    namespace App\Services;

    use App\Models\Booking;
    use App\Models\BookingItem;
    use App\Models\BookingPayment;
    use App\Models\Customer;
    use App\Models\EventTracking;
    use App\Models\Invoice;
    use App\Models\Order;
    use App\Models\OrderItem;
    use App\Models\Person;
    use App\Models\Quotation;
    use App\Models\ServiceEvent;
    use App\Models\InventoryStock;
    use App\Models\BookingEquipment;
    use App\Models\Setting;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use Carbon\Carbon;

    class BookingService
    {
        private function generateBookingNumber(): string
        {
            return 'BK-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }

        private function generateQuoteNumber(): string
        {
            return 'QT-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }

        private function generateOrderNumber(): string
        {
            return 'ORD-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }

        private function generateInvoiceNumber(): string
        {
            return 'INV-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        }

        private function createCustomerFromData(array $data): Customer
        {
            $nameParts = explode(' ', trim($data['customer_name']), 2);
            $firstName = $nameParts[0] ?? 'Customer';
            $lastName = $nameParts[1] ?? 'Account';

            $person = Person::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $data['customer_email'],
                'phone' => $data['customer_phone'] ?? null,
                'address_line_1' => $data['customer_address'] ?? null,
                'country' => 'Philippines',
            ]);

            return Customer::create([
                'person_id' => $person->person_id,
                'customer_code' => 'CUST-' . strtoupper(substr(uniqid(), -6)),
                'is_active' => true,
            ]);
        }

        private function createOrUpdateServiceEvent(?Customer $customer, array $data): ServiceEvent
        {
            $eventDate = is_string($data['event_date']) ? $data['event_date'] : $data['event_date']->format('Y-m-d');
            
            return ServiceEvent::create([
                'customer_id' => $customer->customer_id,
                'event_type_id' => $data['event_type_id'] ?? null,
                'package_id' => $data['package_id'] ?? null,
                'event_date' => $eventDate,
                'event_time' => $data['event_time'],
                'venue' => $data['venue'],
                'guests_count' => $data['guests_count'],
                'service_type' => $data['service_type'] ?? 'buffet',
                'menu_selection_type' => $data['menu_selection_type'] ?? 'custom',
                'has_waiters' => $data['has_waiters'] ?? false,
                'delivery_method' => $data['delivery_method'] ?? 'pickup',
                'special_requests' => $data['special_requests'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_contact_person' => $data['delivery_contact_person'] ?? null,
                'delivery_contact_phone' => $data['delivery_contact_phone'] ?? null,
                'status' => 'pending',
            ]);
        }

        private function calculateTotalAmount(array $data): float
        {
            $total = 0;
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $total += ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1);
                }
            }
            return $total;
        }

        public function requestBooking(?Customer $customer, array $data): Booking
        {
            return DB::transaction(function () use ($customer, $data) {
                if (!$customer && isset($data['customer_email'])) {
                    $customer = $this->createCustomerFromData($data);
                }

                if (!$customer) {
                    throw new \Exception('Customer information is required');
                }

                $serviceEvent = $this->createOrUpdateServiceEvent($customer, $data);
                $totalAmount = $data['total_amount'] ?? $this->calculateTotalAmount($data);
                
                $quotation = Quotation::create([
                    'quote_no' => $this->generateQuoteNumber(),
                    'service_event_id' => $serviceEvent->service_event_id,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                    'valid_until' => now()->addDays(7)->toDateString(),
                ]);

                $booking = Booking::create([
                    'booking_no' => $this->generateBookingNumber(),
                    'service_event_id' => $serviceEvent->service_event_id,
                    'quotation_id' => $quotation->quotation_id,
                    'required_deposit' => $data['required_deposit'] ?? ($totalAmount * 0.3),
                    'booking_status' => 'pending_approval',
                    'requested_date' => $data['event_date'] ?? null,
                    'requested_time' => $data['event_time'] ?? null,
                ]);

                if (isset($data['items']) && is_array($data['items'])) {
                    foreach ($data['items'] as $item) {
                        BookingItem::create([
                            'booking_id' => $booking->booking_id,
                            'menu_item_id' => $item['menu_item_id'] ?? null,
                            'custom_item_name' => $item['custom_item_name'] ?? null,
                            'description' => $item['description'] ?? null,
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'item_type' => $item['item_type'] ?? 'menu_item',
                            'action_type' => 'included',
                            'special_instructions' => $item['special_instructions'] ?? null,
                        ]);
                    }
                }

                return $booking->fresh([
                    'serviceEvent.customer.person',
                    'serviceEvent.eventType',
                    'quotation',
                    'items.menuItem',
                ]);
            });
        }

        private function createOrderFromBooking(Booking $booking): Order
        {
            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'booking_id' => $booking->booking_id,
                'status' => 'pending',
            ]);

            foreach ($booking->items as $item) {
                OrderItem::create([
                    'order_id' => $order->order_id,
                    'menu_item_id' => $item->menu_item_id,
                    'item_name' => $item->custom_item_name ?? $item->menuItem?->name ?? 'Menu Item',
                    'quantity' => $item->quantity,
                    'unit_price_snapshot' => $item->unit_price,
                ]);
            }

            return $order;
        }

        private function createInvoiceFromBooking(Booking $booking): Invoice
        {
            $totalAmount = $booking->quotation?->total_amount ?? 0;
            $paidAmount = $booking->payments()->where('status', 'completed')->sum('amount');

            return Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'booking_id' => $booking->booking_id,
                'subtotal' => $totalAmount,
                'discount' => 0,
                'additional_charges' => 0,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'status' => $paidAmount >= $totalAmount ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid'),
                'due_date' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        private function createEventTracking(Booking $booking): void
        {
            $serviceEvent = $booking->serviceEvent;
            $isMultiDay = false;
            $totalDays = 1;
            
            if ($serviceEvent && $serviceEvent->special_requests) {
                if (preg_match('/MULTI-DAY EVENT: (\d+) days total/', $serviceEvent->special_requests, $matches)) {
                    $isMultiDay = true;
                    $totalDays = (int) $matches[1];
                }
            }
            
            if (strpos($booking->booking_no, 'MLT') !== false) {
                $isMultiDay = true;
            }
            
            $stages = ['preparation', 'ready', 'ongoing', 'completed'];
            $multiDayData = [];
            
            if ($isMultiDay && $serviceEvent) {
                $startDate = Carbon::parse($serviceEvent->event_date);
                for ($i = 1; $i <= $totalDays; $i++) {
                    $currentDate = $startDate->copy()->addDays($i - 1);
                    $multiDayData['daily_progress'][$i] = [
                        'day' => $i,
                        'date' => $currentDate->toDateString(),
                        'completion' => 0,
                        'notes' => '',
                        'menu_items' => [],
                        'attendance' => 0,
                        'registered' => $serviceEvent->guests_count,
                    ];
                }
                $multiDayData['total_days'] = $totalDays;
                $multiDayData['current_day'] = 1;
                $multiDayData['is_multi_day'] = true;
            }
            
            foreach ($stages as $index => $stage) {
                EventTracking::create([
                    'booking_id' => $booking->booking_id,
                    'stage' => $stage,
                    'progress_percentage' => $index === 0 ? 0 : ($index === 3 ? 100 : 0),
                    'stage_started_at' => $index === 0 ? now() : null,
                    'notes' => !empty($multiDayData) && $index === 2 ? json_encode($multiDayData) : null,
                ]);
            }
        }

        private function reserveIngredients(Booking $booking): void
        {
            foreach ($booking->items as $item) {
                if (!$item->menu_item_id) continue;

                foreach ($item->menuItem->recipeIngredients as $recipe) {
                    $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                    
                    if ($stock) {
                        $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                        $stock->increment('reserved_quantity', $requiredQty);
                    } else {
                        $stock = InventoryStock::create([
                            'ingredient_id' => $recipe->ingredient_id,
                            'current_quantity' => 0,
                            'reserved_quantity' => $requiredQty,
                            'minimum_quantity' => 10,
                            'maximum_quantity' => 100,
                            'reorder_point' => 15,
                        ]);
                    }
                }
            }
        }

        private function reserveEquipment(Booking $booking): void
        {
            foreach ($booking->equipment as $equipmentItem) {
                $equipmentItem->update(['status' => 'reserved']);
            }
        }

        private function createCalendarEvent(Booking $booking): void
        {
            $serviceEvent = $booking->serviceEvent;
            if (!$serviceEvent) return;
            
            $calendarData = [
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
                'customer_name' => $serviceEvent->customer?->person?->full_name ?? 'Unknown',
                'event_date' => $serviceEvent->event_date->toDateString(),
                'event_time' => $serviceEvent->event_time,
                'venue' => $serviceEvent->venue,
                'status' => 'booked',
            ];
            
            Setting::updateOrCreate(
                [
                    'group' => 'calendar_events',
                    'key' => 'booking_' . $booking->booking_id,
                ],
                [
                    'value' => json_encode($calendarData),
                    'type' => 'json',
                ]
            );
        }

        public function approve(Booking $booking): Booking
        {
            return DB::transaction(function () use ($booking) {
                $booking->load([
                    'serviceEvent.customer.person',
                    'items.menuItem.recipeIngredients.ingredient.stock',
                    'serviceEvent.package',
                    'equipment',
                    'quotation',
                ]);

                $booking->update(['booking_status' => 'confirmed']);
                
                if ($booking->serviceEvent) {
                    $booking->serviceEvent->update(['status' => 'confirmed']);
                }
                
                if ($booking->quotation) {
                    $booking->quotation->update(['status' => 'approved']);
                }

                $this->createOrderFromBooking($booking);
                $this->createInvoiceFromBooking($booking);
                $this->createEventTracking($booking);
                $this->reserveIngredients($booking);
                $this->reserveEquipment($booking);
                $this->createCalendarEvent($booking);

                return $booking->fresh([
                    'serviceEvent.customer.person',
                    'order',
                    'invoice',
                    'tracking',
                    'quotation',
                ]);
            });
        }

        public function reject(Booking $booking): Booking
        {
            return DB::transaction(function () use ($booking) {
                $booking->update(['booking_status' => 'rejected']);
                
                if ($booking->serviceEvent) {
                    $booking->serviceEvent->update(['status' => 'cancelled']);
                }
                
                if ($booking->quotation) {
                    $booking->quotation->update(['status' => 'rejected']);
                }
                
                return $booking;
            });
        }

        public function cancel(Booking $booking, ?string $reason = null): Booking
        {
            return DB::transaction(function () use ($booking, $reason) {
                $booking->update([
                    'booking_status' => 'cancelled',
                    'cancellation_reason' => $reason,
                ]);
                
                if ($booking->serviceEvent) {
                    $booking->serviceEvent->update(['status' => 'cancelled']);
                }
                
                foreach ($booking->items as $item) {
                    if (!$item->menu_item_id) continue;
                    
                    foreach ($item->menuItem->recipeIngredients as $recipe) {
                        $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                        if ($stock) {
                            $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                            $stock->decrement('reserved_quantity', $requiredQty);
                        }
                    }
                }
                
                Setting::where('group', 'calendar_events')
                    ->where('key', 'booking_' . $booking->booking_id)
                    ->delete();
                
                return $booking;
            });
        }

        public function paymentSummary(Booking $booking): array
        {
            $totalAmount = $booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0;
            $paidAmount = $booking->payments()->where('status', 'completed')->sum('amount');
            $pendingPayments = $booking->payments()->where('status', 'pending')->sum('amount');
            
            return [
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
                'total_amount' => (float) $totalAmount,
                'total_paid' => (float) $paidAmount,
                'pending_amount' => (float) $pendingPayments,
                'balance' => max(0, $totalAmount - $paidAmount),
                'payment_status' => $paidAmount <= 0 ? 'pending' : ($paidAmount < $totalAmount ? 'partial' : 'paid'),
                'payments' => $booking->payments->map(fn($p) => [
                    'id' => $p->payment_id,
                    'amount' => (float) $p->amount,
                    'method' => $p->payment_method,
                    'type' => $p->payment_type,
                    'status' => $p->status,
                    'date' => $p->payment_date?->toDateString(),
                    'reference' => $p->reference_number,
                ]),
            ];
        }

        public function recordPayment(Booking $booking, array $data): BookingPayment
        {
            return DB::transaction(function () use ($booking, $data) {
                $payment = BookingPayment::create([
                    'booking_id' => $booking->booking_id,
                    'payment_number' => 'PAY-' . now()->format('YmdHis') . '-' . $booking->booking_id,
                    'amount' => $data['amount'],
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'payment_type' => $data['payment_type'] ?? 'partial',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'status' => 'completed',
                    'payment_date' => now(),
                    'verified_by' => auth()->id(),
                    'verified_at' => now(),
                ]);
                
                $invoice = $booking->invoice;
                if ($invoice) {
                    $totalPaid = $booking->payments()->where('status', 'completed')->sum('amount');
                    $invoice->update([
                        'paid_amount' => $totalPaid,
                        'status' => $totalPaid >= $invoice->total_amount ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'),
                    ]);
                }
                
                return $payment;
            });
        }
    }