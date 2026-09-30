<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use SoftDeletes;

    protected $table = 'refunds';
    protected $primaryKey = 'refund_id';

    protected $fillable = [
        'refund_number',
        'booking_id',
        'invoice_id',
        'payment_id',
        'requested_by',
        'approved_by',
        'released_by',
        'rejected_by',
        'amount',
        'approved_amount',
        'released_amount',
        'deposit_snapshot',
        'status',
        'reason',
        'admin_notes',
        'rejection_reason',
        'payment_method',
        'reference_number',
        'requested_at',
        'approved_at',
        'released_at',
        'rejected_at',
        'source',
    ];

    protected $casts = [
        'amount' => 'float',
        'approved_amount' => 'float',
        'released_amount' => 'float',
        'deposit_snapshot' => 'float',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'released_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_BOOKING = 'booking';
    public const SOURCE_INVOICE = 'invoice';
    public const SOURCE_ADMIN_DIRECT = 'admin_direct';

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class, 'payment_id', 'payment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by', 'user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by', 'user_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeReleased($query)
    {
        return $query->where('status', self::STATUS_RELEASED);
    }

    // Helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Generate a unique refund number
     */
    public static function generateRefundNumber(): string
    {
        $prefix = 'REF-';
        $lastRefund = self::withTrashed()
            ->where('refund_number', 'LIKE', $prefix . '%')
            ->orderBy('refund_id', 'desc')
            ->first();

        if ($lastRefund) {
            $lastNumber = (int) substr($lastRefund->refund_number, strlen($prefix));
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '000001';
        }

        return $prefix . $newNumber;
    }
}