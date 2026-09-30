<?php
// app/Models/Promotion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Promotion extends Model
{
    use SoftDeletes;

    protected $table = 'promotions';
    protected $primaryKey = 'promotion_id';
    protected $guarded = [];

    protected $casts = [
        'discount_value' => 'float',
        'discounted_price' => 'float',
        'min_booking_amount' => 'float',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_automatic' => 'boolean',
        'allow_stacking' => 'boolean',
        'min_pax' => 'integer',
        'max_pax' => 'integer',
        'max_redemptions' => 'integer',
        'redemption_count' => 'integer',
        'per_customer_limit' => 'integer',
        'days_before_event' => 'integer',
        'sort_order' => 'integer',
        'applicable_menu_item_ids' => 'array',
        'applicable_package_ids' => 'array',
        'applicable_event_type_ids' => 'array',
        'applicable_days_of_week' => 'array',
        'free_addons' => 'array',
        'available_dates' => 'array',
    ];

    protected $appends = [
        'is_expired',
        'status',
        'usage_percentage',
        'formatted_status',
        'status_color'
    ];

    // ==================== RELATIONSHIPS ====================
    
    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class, 'promotion_id', 'promotion_id');
    }

    public function bookings()
    {
        return $this->hasManyThrough(
            Booking::class,
            PromotionRedemption::class,
            'promotion_id',
            'booking_id',
            'promotion_id',
            'booking_id'
        );
    }

    // Get the menu items this promotion applies to
    public function applicableMenuItems()
    {
        if (empty($this->applicable_menu_item_ids)) {
            return collect();
        }
        return MenuItem::whereIn('menu_item_id', $this->applicable_menu_item_ids)->get();
    }

    public function applicablePackages()
    {
        if (empty($this->applicable_package_ids)) {
            return collect();
        }
        return Package::whereIn('package_id', $this->applicable_package_ids)->get();
    }

    public function applicableEventTypes()
    {
        if (empty($this->applicable_event_type_ids)) {
            return collect();
        }
        return EventType::whereIn('event_type_id', $this->applicable_event_type_ids)->get();
    }

    // ==================== ACCESSORS ====================
    
    public function getIsExpiredAttribute(): bool
    {
        $dateExpired = now()->greaterThan($this->end_date);
        $redemptionExpired = $this->max_redemptions && $this->redemption_count >= $this->max_redemptions;
        return $dateExpired || $redemptionExpired;
    }

    public function getStatusAttribute(): string
    {
        if (!$this->is_active) {
            return 'disabled';
        }
        if ($this->is_expired) {
            return 'expired';
        }
        if ($this->start_date->isFuture()) {
            return 'scheduled';
        }
        if ($this->start_date->isPast() && $this->end_date->isFuture()) {
            return 'active';
        }
        return 'inactive';
    }

    public function getFormattedStatusAttribute(): string
    {
        return match($this->status) {
            'active' => 'Active',
            'scheduled' => 'Scheduled',
            'expired' => 'Expired',
            'disabled' => 'Disabled',
            default => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'active' => '#10b981',
            'scheduled' => '#3b82f6',
            'expired' => '#ef4444',
            'disabled' => '#6b7280',
            default => '#6b7280',
        };
    }

    public function getUsagePercentageAttribute(): float
    {
        if (!$this->max_redemptions || $this->max_redemptions <= 0) {
            return 0;
        }
        return min(100, round(($this->redemption_count / $this->max_redemptions) * 100, 1));
    }

    public function getDaysUntilExpiryAttribute(): ?int
    {
        if ($this->end_date->isPast()) {
            return null;
        }
        return now()->diffInDays($this->end_date);
    }

    // ==================== SCOPES ====================
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now());
    }

    public function scopeScheduled($query)
    {
        return $query->where('is_active', true)
            ->whereDate('start_date', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('is_active', true)
            ->whereDate('end_date', '<', now());
    }

    public function scopeDisabled($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('promo_type', $type);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeValidFor($query, $date = null)
    {
        $date = $date ?? now();
        return $query->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }

    public function scopeHasRedemptionCapacity($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('max_redemptions')
                ->orWhereColumn('redemption_count', '<', 'max_redemptions');
        });
    }

    // ==================== HELPER METHODS ====================
    
    public function isApplicableForMenuItem($menuItemId): bool
    {
        if (empty($this->applicable_menu_item_ids)) {
            return true;
        }
        return in_array($menuItemId, $this->applicable_menu_item_ids);
    }

    public function isApplicableForPackage($packageId): bool
    {
        if (empty($this->applicable_package_ids)) {
            return true;
        }
        return in_array($packageId, $this->applicable_package_ids);
    }

    public function isApplicableForEventType($eventTypeId): bool
    {
        if (empty($this->applicable_event_type_ids)) {
            return true;
        }
        return in_array($eventTypeId, $this->applicable_event_type_ids);
    }

    public function hasReachedRedemptionLimit(): bool
    {
        return $this->max_redemptions && $this->redemption_count >= $this->max_redemptions;
    }

    public function calculateDiscount($amount, $quantity = 1): array
    {
        $discountAmount = 0;
        $originalAmount = $amount * $quantity;

        switch ($this->discount_type) {
            case 'percentage':
                $discountAmount = $originalAmount * ($this->discount_value / 100);
                break;
            case 'fixed':
                $discountAmount = $this->discount_value * $quantity;
                break;
            default:
                $discountAmount = 0;
        }

        // If discounted price is set, use that instead
        if ($this->discounted_price !== null) {
            $discountAmount = $originalAmount - ($this->discounted_price * $quantity);
        }

        $finalAmount = max(0, $originalAmount - $discountAmount);

        return [
            'original_amount' => round($originalAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'final_amount' => round($finalAmount, 2),
            'saved_percentage' => $originalAmount > 0 ? round(($discountAmount / $originalAmount) * 100, 1) : 0,
        ];
    }

    public function canBeUsedByCustomer($customerId): bool
    {
        if (!$this->per_customer_limit) {
            return true;
        }

        $usedCount = $this->redemptions()
            ->where('customer_id', $customerId)
            ->count();

        return $usedCount < $this->per_customer_limit;
    }

    public function incrementRedemptionCount(): void
    {
        $this->increment('redemption_count');
    }

    // ==================== FACTORY METHODS ====================
    
    public static function createPromoCode(array $data): self
    {
        return self::create(array_merge($data, [
            'promo_type' => 'promo_code',
            'slug' => Str::slug($data['name']),
        ]));
    }

    public static function createMenuDiscount(array $data): self
    {
        return self::create(array_merge($data, [
            'promo_type' => 'menu_discount',
            'slug' => Str::slug($data['name']),
        ]));
    }

    public static function createLastMinute(array $data): self
    {
        return self::create(array_merge($data, [
            'promo_type' => 'last_minute',
            'is_automatic' => true,
            'slug' => Str::slug($data['name']),
        ]));
    }

    // ==================== STATUS HELPERS ====================
    
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }
}