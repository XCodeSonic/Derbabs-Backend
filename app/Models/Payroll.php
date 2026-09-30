<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payroll extends Model
{
    use SoftDeletes;

    protected $table = 'payrolls';
    protected $primaryKey = 'payroll_id';
    protected $guarded = [];

    protected $casts = [
        'cutoff_start' => 'date',
        'cutoff_end' => 'date',
        'payment_date' => 'date',
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'id',
        'employee_name',
        'employee_code',
        'department_name',
        'position_name',
        'hourly_rate',
        'regular_hours',
        'overtime_hours',
        'total_hours',
        'regular_pay',
        'overtime_pay',
        'gross_pay',
        'sss_deduction',
        'philhealth_deduction',
        'pagibig_deduction',
        'other_deductions',
        'manual_deductions',
        'total_deductions',
        'net_pay',
        'manual_deduction_notes',
        'deduction_type',
        'deduction_category',
        'deduction_reference',
        'deduction_date',
        'deduction_approved_by',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class, 'payroll_id', 'payroll_id');
    }

    public function getIdAttribute()
    {
        return $this->payroll_id;
    }

    public function getEmployeeNameAttribute(): string
    {
        return $this->employee?->full_name ?: 'N/A';
    }

    public function getEmployeeCodeAttribute(): ?string
    {
        return $this->employee?->employee_code;
    }

    public function getDepartmentNameAttribute(): ?string
    {
        return $this->employee?->department?->name;
    }

    public function getPositionNameAttribute(): ?string
    {
        return $this->employee?->position?->title ?? $this->employee?->position?->name;
    }

    public function getHourlyRateAttribute(): float
    {
        return (float) ($this->itemAmount('Hourly Rate') ?: $this->employee?->calculated_hourly_rate ?: 0);
    }

    public function getRegularHoursAttribute(): float
    {
        return (float) $this->itemAmount('Regular Hours');
    }

    public function getOvertimeHoursAttribute(): float
    {
        return (float) $this->itemAmount('Overtime Hours');
    }

    public function getTotalHoursAttribute(): float
    {
        return round($this->regular_hours + $this->overtime_hours, 2);
    }

    public function getRegularPayAttribute(): float
    {
        return (float) $this->itemAmount('Regular Pay');
    }

    public function getOvertimePayAttribute(): float
    {
        return (float) $this->itemAmount('Overtime Pay');
    }

    public function getGrossPayAttribute(): float
    {
        return round($this->earningItems()->sum('amount'), 2);
    }

    public function getSssDeductionAttribute(): float
    {
        return (float) $this->deductionAmountLike('SSS');
    }

    public function getPhilhealthDeductionAttribute(): float
    {
        return (float) $this->deductionAmountLike('PhilHealth');
    }

    public function getPagibigDeductionAttribute(): float
    {
        return (float) $this->deductionAmountLike('Pag-IBIG');
    }

    public function getManualDeductionsAttribute(): float
    {
        return (float) $this->deductionAmountLike('Manual Deduction');
    }

    public function getOtherDeductionsAttribute(): float
    {
        return round(max(0, $this->total_deductions - $this->sss_deduction - $this->philhealth_deduction - $this->pagibig_deduction), 2);
    }

    public function getTotalDeductionsAttribute(): float
    {
        return round($this->deductionItems()->sum('amount'), 2);
    }

    public function getNetPayAttribute(): float
    {
        return round($this->gross_pay - $this->total_deductions, 2);
    }

    public function getManualDeductionNotesAttribute(): ?string
    {
        return $this->manualMetadata()['notes'] ?? null;
    }

    public function getDeductionTypeAttribute(): ?string
    {
        return $this->manualMetadata()['deduction_type'] ?? null;
    }

    public function getDeductionCategoryAttribute(): ?string
    {
        return $this->manualMetadata()['deduction_category'] ?? null;
    }

    public function getDeductionReferenceAttribute(): ?string
    {
        return $this->manualMetadata()['deduction_reference'] ?? null;
    }

    public function getDeductionDateAttribute(): ?string
    {
        return $this->manualMetadata()['deduction_date'] ?? null;
    }

    public function getDeductionApprovedByAttribute(): ?string
    {
        return $this->manualMetadata()['deduction_approved_by'] ?? null;
    }

    private function itemAmount(string $name): float
    {
        return (float) $this->loadedItems()->firstWhere('item_name', $name)?->amount;
    }

    private function deductionAmountLike(string $name): float
    {
        return (float) $this->deductionItems()
            ->filter(fn ($item) => str_contains(strtolower($item->item_name), strtolower($name)))
            ->sum('amount');
    }

    private function manualMetadata(): array
    {
        $manual = $this->deductionItems()
            ->first(fn ($item) => str_contains(strtolower($item->item_name), 'manual deduction'));

        if (! $manual?->description) {
            return [];
        }

        $decoded = json_decode($manual->description, true);
        return is_array($decoded) ? $decoded : ['notes' => $manual->description];
    }

    private function earningItems()
    {
        return $this->loadedItems()->where('item_type', 'earning')
            ->reject(fn ($item) => in_array($item->item_name, ['Regular Hours', 'Overtime Hours', 'Hourly Rate'], true));
    }

    private function deductionItems()
    {
        return $this->loadedItems()->where('item_type', 'deduction');
    }

    private function loadedItems()
    {
        if (! $this->relationLoaded('items')) {
            $this->load('items');
        }

        return $this->getRelation('items');
    }
}
