<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        $guestCount = max(1, (int) ($data['guests_count'] ?? 1));

        $serviceType = strtolower(trim((string) ($data['service_type'] ?? '')));
        if ($serviceType !== '') {
            $data['service_type'] = $serviceType;
        }
        if ($serviceType === 'buffet') {
            $data['delivery_method'] = 'delivery';
        }

        if (($data['booking_scope'] ?? null) === 'single_day') {
            $data['booking_scope'] = 'regular';
        }

        if (isset($data['meal_services']) && is_array($data['meal_services'])) {
            $normalizedMeals = [];
            $mealSequence = [
                'breakfast' => ['label' => 'Breakfast', 'time' => '8:00 AM', 'order' => 0],
                'morning snacks' => ['label' => 'Morning Snacks', 'time' => '10:00 AM', 'order' => 1],
                'morning snack' => ['label' => 'Morning Snacks', 'time' => '10:00 AM', 'order' => 1],
                'lunch' => ['label' => 'Lunch', 'time' => '12:00 PM', 'order' => 2],
                'afternoon snacks' => ['label' => 'Afternoon Snacks', 'time' => '3:00 PM', 'order' => 3],
                'afternoon snack' => ['label' => 'Afternoon Snacks', 'time' => '3:00 PM', 'order' => 3],
                'dinner' => ['label' => 'Dinner', 'time' => '6:00 PM', 'order' => 4],
            ];

            foreach ($data['meal_services'] as $meal) {
                if (!is_array($meal)) {
                    continue;
                }

                $customItems = $meal['custom_items'] ?? [];
                $menuItems = $meal['menu_items'] ?? [];
                $menuName = trim((string) ($meal['menu_name'] ?? ''));
                $notes = trim((string) ($meal['notes'] ?? ''));
                $pricePerHead = (float) ($meal['price_per_head'] ?? 0);
                $totalMealAmount = (float) ($meal['total_meal_amount'] ?? 0);

                $hasMealContent =
                    !empty($customItems) ||
                    !empty($menuItems) ||
                    !empty($meal['menu_item_id']) ||
                    !empty($meal['package_id']) ||
                    $menuName !== '' ||
                    $notes !== '' ||
                    $pricePerHead > 0 ||
                    $totalMealAmount > 0;

                if (!$hasMealContent) {
                    continue;
                }

                $meal['day_number'] = max(1, (int) ($meal['day_number'] ?? 1));
                $normalizedType = strtolower(trim((string) ($meal['meal_type'] ?? 'lunch')));
                $mealConfig = $mealSequence[$normalizedType] ?? null;
                $meal['meal_type'] = $mealConfig['label'] ?? ($meal['meal_type'] ?? 'Lunch');
                $meal['serving_time'] = $meal['serving_time'] ?? ($mealConfig['time'] ?? null);
                $meal['_meal_order'] = $mealConfig['order'] ?? count($mealSequence);
                $meal['pax'] = max(1, (int) ($meal['pax'] ?? $guestCount));
                $meal['price_per_head'] = max(0, (float) ($meal['price_per_head'] ?? 0));

                if (isset($meal['custom_items']) && is_array($meal['custom_items'])) {
                    $meal['custom_items'] = array_values(array_filter(array_map(function ($item) {
                        if (!is_array($item)) {
                            return null;
                        }

                        $item['quantity'] = max(1, (int) ($item['quantity'] ?? 1));
                        $item['unit_price'] = max(0, (float) ($item['unit_price'] ?? $item['price'] ?? 0));
                        $item['price'] = max(0, (float) ($item['price'] ?? $item['unit_price'] ?? 0));

                        return $item;
                    }, $meal['custom_items'])));
                }

                $normalizedMeals[] = $meal;
            }

            usort($normalizedMeals, function (array $left, array $right): int {
                $dayComparison = ((int) ($left['day_number'] ?? 1)) <=> ((int) ($right['day_number'] ?? 1));
                if ($dayComparison !== 0) {
                    return $dayComparison;
                }

                $mealComparison = ((int) ($left['_meal_order'] ?? 99)) <=> ((int) ($right['_meal_order'] ?? 99));
                if ($mealComparison !== 0) {
                    return $mealComparison;
                }

                return strcmp((string) ($left['serving_time'] ?? ''), (string) ($right['serving_time'] ?? ''));
            });

            $normalizedMeals = array_map(function (array $meal): array {
                unset($meal['_meal_order']);
                return $meal;
            }, $normalizedMeals);

            $data['meal_services'] = $normalizedMeals;
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            // Customer information - ADD THESE FIELDS
            'customer_id' => ['nullable', 'exists:customers,customer_id'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],

            // Event information
            'event_type_id' => ['required', 'exists:event_types,event_type_id'],
            'package_id' => ['nullable', 'exists:packages,package_id'],
            'event_date' => ['required', 'date'],
            'event_end_date' => ['nullable', 'required_if:booking_scope,multi_day', 'date', 'after_or_equal:event_date'],
            'event_time' => ['required', 'string', 'max:50'],
            'venue' => ['required', 'string', 'max:500'],
            'guests_count' => ['required', 'integer', 'min:1'],
            'service_type' => ['required', Rule::in(['buffet', 'packed', 'tray'])],
            'menu_selection_type' => ['nullable', Rule::in(['package', 'custom'])],
            'has_waiters' => ['nullable', 'boolean'],
            'delivery_method' => ['nullable', Rule::in(['pickup', 'delivery'])],
            'special_requests' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_contact_person' => ['nullable', 'string', 'max:100'],
            'delivery_contact_phone' => ['nullable', 'string', 'max:30'],
            'delivery_instructions' => ['nullable', 'string'],
            'scheduled_delivery_time' => ['nullable', 'date'],
            'delivery_zone_id' => ['nullable', 'exists:delivery_zones,zone_id'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],

            // Financial
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'required_deposit' => ['nullable', 'numeric', 'min:0'],

            // Promo
            'promo_id' => ['nullable', 'integer'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'promo_name' => ['nullable', 'string', 'max:100'],
            'promo_discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'promo_discount_value' => ['nullable', 'numeric', 'min:0'],
            'promo_discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Booking scope
            'booking_scope' => ['required', Rule::in(['regular', 'multi_day'])],

            // Fees and charges
            'transportation_fee' => ['nullable', 'numeric', 'min:0'],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'service_crew_fee' => ['nullable', 'numeric', 'min:0'],
            'equipment_rental' => ['nullable', 'numeric', 'min:0'],
            'extra_food_fee' => ['nullable', 'numeric', 'min:0'],
            'additional_charges' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check', 'Cash', 'GCash', 'Maya', 'Bank Transfer', 'Credit Card'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'transaction_id' => ['nullable', 'string', 'max:100'],

            // Charges array
            'charges' => ['nullable', 'array'],
            'charges.*.charge_kind' => ['nullable', Rule::in(['charge', 'discount'])],
            'charges.*.charge_type' => ['nullable', 'string', 'max:80'],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0'],

            // Meal services
            'meal_services' => ['nullable', 'array'],
            'meal_services.*.service_date' => ['nullable', 'date'],
            'meal_services.*.menu_source' => ['nullable', Rule::in(['package', 'custom'])],
            'meal_services.*.menu_mode' => ['nullable', Rule::in(['package', 'custom'])],
            'meal_services.*.day_number' => ['nullable', 'integer', 'min:1'],
            'meal_services.*.meal_type' => ['required_with:meal_services', Rule::in(['Breakfast', 'Morning Snacks', 'Lunch', 'Afternoon Snacks', 'Dinner'])],
            'meal_services.*.serving_time' => ['nullable', 'string', 'max:50'],
            'meal_services.*.preparation_time' => ['nullable', 'string', 'max:50'],
            'meal_services.*.dispatch_time' => ['nullable', 'string', 'max:50'],
            'meal_services.*.arrival_time' => ['nullable', 'string', 'max:50'],
            'meal_services.*.pax' => ['required_with:meal_services', 'integer', 'min:1'],
            'meal_services.*.menu_item_id' => ['nullable', 'exists:menu_items,menu_item_id'],
            'meal_services.*.package_id' => ['nullable', 'exists:packages,package_id'],
            'meal_services.*.menu_name' => ['nullable', 'string', 'max:255'],
            'meal_services.*.menu_description' => ['nullable', 'string'],
            'meal_services.*.price_per_head' => ['required_with:meal_services', 'numeric', 'min:0'],
            'meal_services.*.total_meal_amount' => ['nullable', 'numeric', 'min:0'],
            'meal_services.*.notes' => ['nullable', 'string'],
            'meal_services.*.meal_status' => ['nullable', 'string', 'max:40'],
            'meal_services.*.filters' => ['nullable'],
            'meal_services.*.dietary_filters' => ['nullable'],
            'meal_services.*.custom_items' => ['nullable', 'array'],
            'meal_services.*.custom_items.*.menu_item_id' => ['nullable', 'exists:menu_items,menu_item_id'],
            'meal_services.*.custom_items.*.item_name' => ['nullable', 'string', 'max:200'],
            'meal_services.*.custom_items.*.name' => ['nullable', 'string', 'max:200'],
            'meal_services.*.custom_items.*.description' => ['nullable', 'string'],
            'meal_services.*.custom_items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'meal_services.*.custom_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'meal_services.*.custom_items.*.price' => ['nullable', 'numeric', 'min:0'],
            'meal_services.*.custom_items.*.notes' => ['nullable', 'string'],

            // Items
            'items' => ['nullable', 'array'],
            'items.*.menu_item_id' => ['nullable', 'exists:menu_items,menu_item_id'],
            'items.*.custom_item_name' => ['nullable', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.item_type' => ['nullable', Rule::in(['menu_item', 'custom_item', 'service_fee', 'add_on'])],
            'items.*.action_type' => ['nullable', Rule::in(['included', 'added', 'removed'])],
            'items.*.special_instructions' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $meals = $this->input('meal_services', []);
                if (!is_array($meals)) {
                    return;
                }

                $scope = $this->input('booking_scope', 'regular');
                $eventDate = $this->input('event_date');
                $eventEndDate = $this->input('event_end_date', $eventDate);
                $maximumDay = 1;

                if ($scope === 'multi_day' && $eventDate && $eventEndDate) {
                    try {
                        $start = new \DateTimeImmutable((string) $eventDate);
                        $end = new \DateTimeImmutable((string) $eventEndDate);
                        $maximumDay = max(1, $start->diff($end)->days + 1);
                    } catch (\Throwable) {
                        $maximumDay = 1;
                    }
                }

                $seen = [];
                foreach ($meals as $index => $meal) {
                    if (!is_array($meal)) {
                        continue;
                    }

                    $day = max(1, (int) ($meal['day_number'] ?? 1));
                    $mealType = strtolower(trim((string) ($meal['meal_type'] ?? '')));
                    $key = $day . '|' . $mealType;

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "meal_services.{$index}.meal_type",
                            "{$meal['meal_type']} may only be added once for Day {$day}."
                        );
                    }
                    $seen[$key] = true;

                    if ($scope === 'regular' && $day !== 1) {
                        $validator->errors()->add(
                            "meal_services.{$index}.day_number",
                            'Regular events may only contain Day 1 meal services.'
                        );
                    }

                    if ($scope === 'multi_day' && $day > $maximumDay) {
                        $validator->errors()->add(
                            "meal_services.{$index}.day_number",
                            "Day {$day} is outside the configured {$maximumDay}-day event duration."
                        );
                    }
                }
            },
        ];
    }

}
