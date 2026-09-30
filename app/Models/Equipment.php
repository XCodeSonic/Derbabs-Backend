<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipment extends Model
{
    use SoftDeletes;

    protected $table = 'equipment';
    protected $primaryKey = 'equipment_id';
    protected $guarded = [];

    protected $casts = [
        'total_quantity' => 'integer',
        'last_maintenance' => 'date',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'reserved_quantity',
        'in_use_quantity',
        'damaged_quantity',
        'missing_quantity',
        'available_quantity',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function reservations()
    {
        return $this->hasMany(BookingEquipment::class, 'equipment_id', 'equipment_id');
    }

    public function getReservedQuantityAttribute(): int
    {
        // Always derive this value from active booking reservations. The legacy
        // physical column can become stale when an event approval changes a
        // reservation to checked_out.
        return (int) $this->reservations()
            ->whereIn('status', ['reserved', 'checked_out'])
            ->sum('quantity_reserved');
    }

    public function getInUseQuantityAttribute(): int
    {
        return (int) $this->reservations()
            ->where('status', 'checked_out')
            ->sum('quantity_used');
    }

    public function getDamagedQuantityAttribute(): int
    {
        if (array_key_exists('damaged_quantity', $this->attributes)) {
            return (int) $this->attributes['damaged_quantity'];
        }

        return (int) $this->reservations()->sum('quantity_damaged');
    }

    public function getMissingQuantityAttribute(): int
    {
        if (array_key_exists('missing_quantity', $this->attributes)) {
            return (int) $this->attributes['missing_quantity'];
        }

        return (int) $this->reservations()->sum('quantity_missing');
    }

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, (int) $this->total_quantity - $this->reserved_quantity);
    }
}
