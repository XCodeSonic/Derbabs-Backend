<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Booking extends Model
{
    use SoftDeletes;

    protected $table = 'bookings';
    protected $primaryKey = 'booking_id';
    protected $guarded = [];

    protected $casts = [
        'required_deposit'       => 'float',
        'requested_date'         => 'date',
        'reschedule_proposed_at' => 'datetime',
        'original_event_date'    => 'date',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================
    public function serviceEvent()
    {
        return $this->belongsTo(ServiceEvent::class, 'service_event_id', 'service_event_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'quotation_id');
    }

    public function items()
    {
        return $this->hasMany(BookingItem::class, 'booking_id', 'booking_id');
    }

    public function equipment()
    {
        return $this->hasMany(BookingEquipment::class, 'booking_id', 'booking_id');
    }

    public function payments()
    {
        return $this->hasMany(BookingPayment::class, 'booking_id', 'booking_id');
    }

    public function charges()
    {
        return $this->hasMany(BookingCharge::class, 'booking_id', 'booking_id');
    }

    public function review()
    {
        return $this->hasOne(Review::class, 'booking_id', 'booking_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'booking_id', 'booking_id');
    }

    public function eventDays()
    {
        return $this->hasMany(EventDay::class, 'booking_id', 'booking_id')->orderBy('day_number');
    }

    public function mealServices()
    {
        return $this->hasMany(MealService::class, 'booking_id', 'booking_id')
            ->orderBy('event_day_id')
            ->orderBy('serving_time')
            ->orderBy('meal_service_id');
    }

    public function eventChecklistItems()
    {
        return $this->hasMany(EventChecklistItem::class, 'booking_id', 'booking_id');
    }

    public function deliveryTrackings()
    {
        return $this->hasMany(EventDeliveryTracking::class, 'booking_id', 'booking_id');
    }

    public function order()
    {
        return $this->hasOne(Order::class, 'booking_id', 'booking_id');
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'booking_id', 'booking_id');
    }

    public function tracking()
    {
        return $this->hasMany(EventTracking::class, 'booking_id', 'booking_id');
    }

    public function currentTracking()
    {
        return $this->hasOne(EventTracking::class, 'booking_id', 'booking_id')
            ->whereNull('stage_completed_at')
            ->latest();
    }

    // ============================================================
    // ACCESSORS
    // ============================================================
    public function getTotalAmountAttribute()
    {
        return $this->quotation?->total_amount ?? 0;
    }

    public function getPaidAmountAttribute()
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    public function getBalanceAttribute()
    {
        return max(0, $this->total_amount - $this->paid_amount);
    }

    public function getInventoryDeductedAttribute()
    {
        return (bool) Setting::getValue('inventory_deductions', 'booking_' . $this->booking_id, false);
    }

    public function profitabilitySnapshot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\BookingCostSnapshot::class, 'booking_id', 'booking_id');
    }

    // ============================================================
    // DEPOSIT POLICY STATE (stored in settings, no migration)
    // ============================================================
    public function getDepositPolicyStateAttribute(): array
    {
        $state = Setting::getValue('booking_deposit_policy', 'booking_' . $this->booking_id, null);
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = is_array($decoded) ? $decoded : null;
        }
        return is_array($state) ? $state : [];
    }

    public function getDepositDecisionStatusAttribute(): ?string
    {
        return $this->deposit_policy_state['decision_status'] ?? null;
    }

    public function getDepositDecisionActionAttribute(): ?string
    {
        return $this->deposit_policy_state['decision_action'] ?? null;
    }

    public function getDepositDecisionNotesAttribute(): ?string
    {
        return $this->deposit_policy_state['decision_notes'] ?? null;
    }

    public function getDepositExtendedUntilAttribute()
    {
        $value = $this->deposit_policy_state['extended_until'] ?? null;
        return $value ? Carbon::parse($value) : null;
    }

    public function getDepositDecisionAtAttribute()
    {
        $value = $this->deposit_policy_state['decision_at'] ?? null;
        return $value ? Carbon::parse($value) : null;
    }

    // ============================================================
    // REFUND REQUEST STATE (stored in settings, no migration)
    // ============================================================
    public function getRefundRequestStateAttribute(): array
    {
        $state = Setting::getValue('booking_refund_requests', 'booking_' . $this->booking_id, null);
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = is_array($decoded) ? $decoded : null;
        }
        return is_array($state) ? $state : [];
    }

    public function getRefundStatusAttribute(): ?string
    {
        return $this->refund_request_state['status'] ?? null;
    }

    public function getRefundAmountAttribute(): float
    {
        return (float) ($this->refund_request_state['amount'] ?? 0);
    }

    public function getRefundReasonAttribute(): ?string
    {
        return $this->refund_request_state['reason'] ?? null;
    }

    // ============================================================
    // RESCHEDULE WORKFLOW ACCESSORS (NEW)
    // ============================================================

    /**
     * 'admin' | 'customer' | null — who initiated the current proposal
     */
    public function getRescheduleInitiatedByAttribute(): ?string
    {
        return $this->reschedule_proposed_by ?: null;
    }

    /**
     * True when the current state is waiting for someone to respond.
     */
    public function getHasPendingRescheduleAttribute(): bool
    {
        return $this->reschedule_status === 'pending';
    }

    /**
     * True when the customer rejected or ignored the proposal and
     * the booking ended up in the "rejected reschedule" state.
     */
    public function getIsRescheduleRejectedAttribute(): bool
    {
        return $this->booking_status === 'reschedule_rejected';
    }
}
