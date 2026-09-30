<?php
// app/Http/Controllers/Api/PromotionController.php

namespace App\Http\Controllers\Api;

use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\MenuItem;
use App\Models\Package;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PromotionController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $query = Promotion::query();

        // Filters
        if ($request->filled('status')) {
            match($request->status) {
                'active' => $query->active(),
                'scheduled' => $query->scheduled(),
                'expired' => $query->expired(),
                'disabled' => $query->disabled(),
                default => null,
            };
        }

        if ($request->filled('promo_type')) {
            $query->where('promo_type', $request->promo_type);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->boolean('public')) {
            $query->active()->hasRedemptionCapacity();
        }

        $promotions = $query->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $promotions->getCollection()->transform(function ($promotion) {
            return $this->formatPromotion($promotion);
        });

        return $this->ok($promotions);
    }

    public function stats()
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        return $this->ok([
            'total' => Promotion::count(),
            'active' => Promotion::active()->count(),
            'scheduled' => Promotion::scheduled()->count(),
            'expired' => Promotion::expired()->count(),
            'disabled' => Promotion::disabled()->count(),
            'redemptions_this_month' => PromotionRedemption::whereMonth('created_at', $now->month)
                ->whereYear('created_at', $now->year)
                ->count(),
            'total_redemptions' => PromotionRedemption::count(),
            'total_discount_given' => PromotionRedemption::sum('discount_amount'),
            'by_type' => Promotion::select('promo_type')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('promo_type')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        
        $promotion = Promotion::create(array_merge($validated, [
            'slug' => Str::slug($validated['name']),
            'redemption_count' => 0,
        ]));

        // Send notification if promotion is active
        if ($promotion->is_active && $promotion->start_date <= now()) {
            $this->notificationService->notifySystemEvent(
                'new_promotion_published',
                "New promotion '{$promotion->name}' is now available!",
                ['promotion_id' => $promotion->promotion_id],
                ['admin', 'customer']
            );
        }

        return $this->ok(
            $this->formatPromotion($promotion),
            'Promotion created successfully'
        );
    }

    public function show(Promotion $promotion)
    {
        $promotion->load(['applicableMenuItems', 'applicablePackages', 'applicableEventTypes']);
        return $this->ok($this->formatPromotion($promotion, true));
    }

    public function update(Request $request, Promotion $promotion)
    {
        $validated = $this->validatePayload($request, $promotion);
        $promotion->update($validated);

        return $this->ok(
            $this->formatPromotion($promotion->fresh()),
            'Promotion updated successfully'
        );
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();
        return $this->ok(null, 'Promotion deleted successfully');
    }

    public function toggleActive(Promotion $promotion)
    {
        $promotion->update(['is_active' => !$promotion->is_active]);
        
        if ($promotion->is_active && $promotion->start_date <= now()) {
            $this->notificationService->notifySystemEvent(
                'new_promotion_published',
                "Promotion '{$promotion->name}' has been activated!",
                ['promotion_id' => $promotion->promotion_id],
                ['admin', 'customer']
            );
        }

        return $this->ok(
            $this->formatPromotion($promotion->fresh()),
            'Promotion ' . ($promotion->is_active ? 'activated' : 'deactivated')
        );
    }

    public function duplicate(Promotion $promotion)
    {
        $newPromotion = $promotion->replicate();
        $newPromotion->name = $promotion->name . ' (Copy)';
        $newPromotion->slug = Str::slug($newPromotion->name . '-' . uniqid());
        $newPromotion->code = $promotion->code ? $promotion->code . '-' . uniqid() : null;
        $newPromotion->redemption_count = 0;
        $newPromotion->save();

        return $this->ok(
            $this->formatPromotion($newPromotion),
            'Promotion duplicated successfully'
        );
    }

    public function getActivePromotions(Request $request)
    {
        $promotions = Promotion::active()
            ->hasRedemptionCapacity()
            ->when($request->filled('promo_type'), function ($q) use ($request) {
                $q->where('promo_type', $request->promo_type);
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($promotion) => $this->formatPromotion($promotion));

        return $this->ok($promotions);
    }

    public function validatePromoCode(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'booking_amount' => 'nullable|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,customer_id',
            'menu_item_ids' => 'nullable|array',
            'package_ids' => 'nullable|array',
            'event_type_id' => 'nullable|exists:event_types,event_type_id',
            'pax_count' => 'nullable|integer|min:1',
        ]);

        $promotion = Promotion::where('code', strtoupper($data['code']))
            ->where('promo_type', 'promo_code')
            ->first();

        if (!$promotion) {
            return $this->fail('Invalid promo code', 422);
        }

        // Check if promotion is valid
        if (!$promotion->is_active) {
            return $this->fail('This promo code is currently disabled', 422);
        }

        if ($promotion->is_expired) {
            return $this->fail('This promo code has expired', 422);
        }

        if ($promotion->start_date->isFuture()) {
            return $this->fail('This promo code is not yet active', 422);
        }

        // Check redemption limit
        if ($promotion->hasReachedRedemptionLimit()) {
            return $this->fail('This promo code has reached its redemption limit', 422);
        }

        // Check customer limit
        if ($data['customer_id'] && !$promotion->canBeUsedByCustomer($data['customer_id'])) {
            return $this->fail('You have already used this promo code the maximum number of times', 422);
        }

        // Check minimum booking amount
        if ($data['booking_amount'] && $promotion->min_booking_amount) {
            if ($data['booking_amount'] < $promotion->min_booking_amount) {
                return $this->fail(
                    "Minimum booking amount of ₱" . number_format($promotion->min_booking_amount, 2) . " required",
                    422
                );
            }
        }

        // Check applicable items
        if (!empty($promotion->applicable_menu_item_ids) && !empty($data['menu_item_ids'])) {
            $applicable = array_intersect($data['menu_item_ids'], $promotion->applicable_menu_item_ids);
            if (empty($applicable)) {
                return $this->fail('This promo code does not apply to the selected menu items', 422);
            }
        }

        if (!empty($promotion->applicable_package_ids) && !empty($data['package_ids'])) {
            $applicable = array_intersect($data['package_ids'], $promotion->applicable_package_ids);
            if (empty($applicable)) {
                return $this->fail('This promo code does not apply to the selected packages', 422);
            }
        }

        if ($data['event_type_id'] && !$promotion->isApplicableForEventType($data['event_type_id'])) {
            return $this->fail('This promo code does not apply to this event type', 422);
        }

        // Calculate discount
        $amount = $data['booking_amount'] ?? 0;
        $discount = $promotion->calculateDiscount($amount);

        return $this->ok([
            'promotion' => $this->formatPromotion($promotion),
            'discount' => $discount,
            'is_valid' => true,
        ]);
    }

    public function redeemPromoCode(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'booking_id' => 'required|exists:bookings,booking_id',
            'customer_id' => 'nullable|exists:customers,customer_id',
        ]);

        // First validate the promo code
        $validation = $this->validatePromoCode($request);
        
        if ($validation->getStatusCode() !== 200) {
            return $validation;
        }

        $promotion = Promotion::where('code', strtoupper($data['code']))->first();
        $booking = Booking::with(['items', 'quotation'])->findOrFail($data['booking_id']);
        $customer = $data['customer_id'] 
            ? Customer::find($data['customer_id']) 
            : $booking->serviceEvent?->customer;

        // Calculate the total booking amount
        $totalAmount = $booking->quotation?->total_amount ?? 0;
        $discount = $promotion->calculateDiscount($totalAmount);

        // Create redemption record
        $redemption = PromotionRedemption::create([
            'promotion_id' => $promotion->promotion_id,
            'booking_id' => $booking->booking_id,
            'customer_id' => $customer?->customer_id,
            'discount_amount' => $discount['discount_amount'],
            'original_amount' => $discount['original_amount'],
            'final_amount' => $discount['final_amount'],
            'promo_code_used' => $promotion->code,
        ]);

        // Increment redemption count
        $promotion->incrementRedemptionCount();

        // Update the booking quotation
        if ($booking->quotation) {
            $booking->quotation->update([
                'total_amount' => $discount['final_amount']
            ]);
        }

        // Send notification
        $this->notificationService->notifySystemEvent(
            'promotion_redeemed',
            "Promo code '{$promotion->code}' redeemed for booking {$booking->booking_no}",
            [
                'promotion_id' => $promotion->promotion_id,
                'booking_id' => $booking->booking_id,
                'discount_amount' => $discount['discount_amount'],
            ],
            ['admin']
        );

        return $this->ok([
            'redemption' => $redemption,
            'discount' => $discount,
        ], 'Promo code applied successfully');
    }

    public function getRedemptions(Request $request, Promotion $promotion)
    {
        $redemptions = $promotion->redemptions()
            ->with(['booking.serviceEvent.customer.person'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->ok($redemptions);
    }

    public function getAnalytics(Promotion $promotion)
    {
        $redemptions = $promotion->redemptions;

        return $this->ok([
            'promotion_id' => $promotion->promotion_id,
            'name' => $promotion->name,
            'total_redemptions' => $redemptions->count(),
            'total_discount_given' => $redemptions->sum('discount_amount'),
            'redemption_limit' => $promotion->max_redemptions,
            'usage_percentage' => $promotion->usage_percentage,
            'daily_redemptions' => $redemptions->groupBy(function ($redemption) {
                return $redemption->created_at->format('Y-m-d');
            })->map->count(),
            'top_customers' => $redemptions->groupBy('customer_id')
                ->map(function ($group) {
                    $customer = $group->first()->customer;
                    return [
                        'customer_name' => $customer?->person?->full_name ?? 'Guest',
                        'count' => $group->count(),
                        'total_saved' => $group->sum('discount_amount'),
                    ];
                })
                ->sortByDesc('count')
                ->take(5)
                ->values(),
        ]);
    }

    public function sendExpiryReminders()
    {
        $expiringSoon = Promotion::where('is_active', true)
            ->whereDate('end_date', '<=', now()->addDays(3))
            ->whereDate('end_date', '>=', now())
            ->get();

        foreach ($expiringSoon as $promotion) {
            $this->notificationService->notifySystemEvent(
                'promotion_expiring_soon',
                "Promotion '{$promotion->name}' expires in " . $promotion->days_until_expiry . " days",
                [
                    'promotion_id' => $promotion->promotion_id,
                    'name' => $promotion->name,
                    'days_left' => $promotion->days_until_expiry,
                ],
                ['admin']
            );
        }

        return $this->ok([
            'notified' => $expiringSoon->count(),
        ], 'Expiry reminders sent');
    }

    // ==================== HELPER METHODS ====================

    private function validatePayload(Request $request, ?Promotion $promotion = null): array
    {
        $required = $promotion ? 'sometimes' : 'required';
        $id = $promotion?->promotion_id;

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')->ignore($id, 'promotion_id'),
            ],
            'description' => ['nullable', 'string'],
            'promo_type' => [$required, Rule::in([
                'menu_discount',
                'package_discount',
                'promo_package',
                'promo_code',
                'last_minute',
                'value_added',
                'booking_planning',
                'package_upgrade',
                'referral_loyalty',
                'event_specific'
            ])],
            'discount_type' => [$required, Rule::in(['percentage', 'fixed', 'free_addon'])],
            'discount_value' => [$required, 'numeric', 'min:0'],
            'discounted_price' => ['nullable', 'numeric', 'min:0'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'min_pax' => ['nullable', 'integer', 'min:1'],
            'max_pax' => ['nullable', 'integer', 'min:1', 'gte:min_pax'],
            'min_booking_amount' => ['nullable', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'allow_stacking' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_automatic' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'days_before_event' => ['nullable', 'integer', 'min:1'],
            'applicable_menu_item_ids' => ['nullable', 'array'],
            'applicable_menu_item_ids.*' => ['exists:menu_items,menu_item_id'],
            'applicable_package_ids' => ['nullable', 'array'],
            'applicable_package_ids.*' => ['exists:packages,package_id'],
            'applicable_event_type_ids' => ['nullable', 'array'],
            'applicable_event_type_ids.*' => ['exists:event_types,event_type_id'],
            'applicable_days_of_week' => ['nullable', 'array'],
            'applicable_days_of_week.*' => [Rule::in([0, 1, 2, 3, 4, 5, 6])],
            'free_addons' => ['nullable', 'array'],
            'free_addons.*' => ['string', 'max:255'],
            'complimentary_items' => ['nullable', 'string'],
            'available_dates' => ['nullable', 'array'],
            'available_dates.*' => ['date'],
            'banner_image_url' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function formatPromotion(Promotion $promotion, bool $detailed = false): array
    {
        $base = [
            'id' => $promotion->promotion_id,
            'name' => $promotion->name,
            'slug' => $promotion->slug,
            'code' => $promotion->code,
            'description' => $promotion->description,
            'promo_type' => $promotion->promo_type,
            'discount_type' => $promotion->discount_type,
            'discount_value' => (float) $promotion->discount_value,
            'discounted_price' => $promotion->discounted_price ? (float) $promotion->discounted_price : null,
            'start_date' => $promotion->start_date->format('Y-m-d'),
            'end_date' => $promotion->end_date->format('Y-m-d'),
            'start_time' => $promotion->start_time?->format('H:i'),
            'end_time' => $promotion->end_time?->format('H:i'),
            'min_pax' => $promotion->min_pax,
            'max_pax' => $promotion->max_pax,
            'min_booking_amount' => $promotion->min_booking_amount ? (float) $promotion->min_booking_amount : null,
            'max_redemptions' => $promotion->max_redemptions,
            'redemption_count' => $promotion->redemption_count,
            'per_customer_limit' => $promotion->per_customer_limit,
            'allow_stacking' => (bool) $promotion->allow_stacking,
            'is_active' => (bool) $promotion->is_active,
            'is_featured' => (bool) $promotion->is_featured,
            'is_automatic' => (bool) $promotion->is_automatic,
            'status' => $promotion->status,
            'formatted_status' => $promotion->formatted_status,
            'status_color' => $promotion->status_color,
            'is_expired' => $promotion->is_expired,
            'usage_percentage' => $promotion->usage_percentage,
            'days_until_expiry' => $promotion->days_until_expiry,
            'sort_order' => $promotion->sort_order,
            'banner_image_url' => $promotion->banner_image_url,
            'created_at' => $promotion->created_at->toDateTimeString(),
            'updated_at' => $promotion->updated_at->toDateTimeString(),
        ];

        if ($detailed) {
            $base['applicable_menu_items'] = $promotion->applicableMenuItems()->map(function ($item) {
                return [
                    'id' => $item->menu_item_id,
                    'name' => $item->name,
                    'price' => (float) $item->price,
                ];
            });

            $base['applicable_packages'] = $promotion->applicablePackages()->map(function ($package) {
                return [
                    'id' => $package->package_id,
                    'name' => $package->name,
                    'base_price' => (float) $package->base_price_per_pax,
                ];
            });

            $base['applicable_event_types'] = $promotion->applicableEventTypes()->map(function ($eventType) {
                return [
                    'id' => $eventType->event_type_id,
                    'name' => $eventType->name,
                ];
            });

            $base['free_addons'] = $promotion->free_addons;
            $base['complimentary_items'] = $promotion->complimentary_items;
            $base['available_dates'] = $promotion->available_dates;
            $base['days_before_event'] = $promotion->days_before_event;
            $base['redemptions_count'] = $promotion->redemptions()->count();
            $base['total_discount_given'] = (float) $promotion->redemptions()->sum('discount_amount');
        }

        return $base;
    }
}