<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function previewMany(array $employeeIds, string $start, string $end, array $options = []): array
    {
        $items = collect($employeeIds)->map(function ($employeeId) use ($start, $end, $options) {
            $employee = Employee::with(['person', 'department', 'position.salaryGrade'])->findOrFail($employeeId);
            $calculation = $this->calculateEmployeePayroll($employee, $start, $end, false, $options);

            return [
                'employee_id' => $employee->employee_id,
                'employee_code' => $employee->employee_code,
                'employee_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title ?? $employee->position?->name,
                'employee' => [
                    'employee_id' => $employee->employee_id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->title ?? $employee->position?->name,
                    'employee_type' => $employee->employee_type ?? $employee->employment_type,
                ],
                'calculation' => $calculation,
            ];
        })->values();

        return [
            'period_start' => $start,
            'period_end' => $end,
            'items' => $items,
            'preview' => $items,
            'summary' => [
                'total_employees' => $items->count(),
                'total_regular_hours' => round((float) $items->sum('calculation.regular_hours'), 2),
                'total_overtime_hours' => round((float) $items->sum('calculation.overtime_hours'), 2),
                'total_gross_pay' => round((float) $items->sum('calculation.gross_pay'), 2),
                'total_deductions' => round((float) $items->sum('calculation.total_deductions'), 2),
                'total_net_pay' => round((float) $items->sum('calculation.net_pay'), 2),
            ],
        ];
    }

    public function generate(
        int $employeeId,
        string $start,
        string $end,
        ?string $notes = null,
        array $options = []
    ): Payroll {
        return DB::transaction(function () use ($employeeId, $start, $end, $notes, $options) {
            $employee = Employee::with(['person', 'department', 'position.salaryGrade'])->findOrFail($employeeId);

            $calculation = $this->calculateEmployeePayroll($employee, $start, $end, true, $options);

            // ⭐ HARDENED: Every monetary column is written explicitly from
            //    the fresh calculation. `total_deductions` is recomputed from
            //    the individual deduction columns rather than trusted from
            //    the calculation array, so a bug in the calc layer cannot
            //    persist a wrong total to the DB.
            $sssDeduction = round((float) ($calculation['sss_deduction'] ?? 0), 2);
            $philhealthDeduction = round((float) ($calculation['philhealth_deduction'] ?? 0), 2);
            $pagibigDeduction = round((float) ($calculation['pagibig_deduction'] ?? 0), 2);
            $withholdingTax = round((float) ($calculation['withholding_tax'] ?? 0), 2);
            $otherDeduction = round((float) ($calculation['other_deduction'] ?? 0), 2);

            $recomputedTotalDeductions = round(
                $sssDeduction
                    + $philhealthDeduction
                    + $pagibigDeduction
                    + $withholdingTax
                    + $otherDeduction,
                2
            );

            $grossPay = round((float) $calculation['gross_pay'], 2);
            $netPay = round($grossPay - $recomputedTotalDeductions, 2);

            $calculationPayload = [
                'regular_hours' => $calculation['regular_hours'],
                'overtime_hours' => $calculation['overtime_hours'],
                'total_hours' => $calculation['total_hours'],
                'hourly_rate' => $calculation['hourly_rate'],
                'regular_pay' => $calculation['regular_pay'],
                'overtime_pay' => $calculation['overtime_pay'],
                'gross_pay' => $grossPay,
                'sss_deduction' => $sssDeduction,
                'philhealth_deduction' => $philhealthDeduction,
                'pagibig_deduction' => $pagibigDeduction,
                'withholding_tax' => $withholdingTax,
                // ⭐ NOTE: This column name is `other_deductions` (plural).
                //    If your migration defines `other_deduction` (singular),
                //    change this key to match. Both controller reads fall
                //    back to the other name for compatibility.
                'other_deductions' => $otherDeduction,
                'total_deductions' => $recomputedTotalDeductions,
                'net_pay' => $netPay,
            ];

            $payroll = Payroll::withTrashed()
                ->where('employee_id', $employeeId)
                ->whereDate('cutoff_start', $start)
                ->whereDate('cutoff_end', $end)
                ->first();

            if ($payroll) {
                if ($payroll->status === 'paid') {
                    throw ValidationException::withMessages([
                        'employee_ids' => "Payroll for {$employee->full_name} covering {$start} to {$end} is already paid and archived.",
                    ]);
                }

                if ($payroll->trashed()) {
                    $payroll->restore();
                }

                $payroll->update(array_merge($calculationPayload, [
                    'status' => 'calculated',
                    'payment_date' => $payroll->payment_date ?: now()->toDateString(),
                    'calculated_by' => auth()->id(),
                    'calculated_at' => now(),
                    'notes' => $notes ?? $payroll->notes,
                ]));
            } else {
                $payroll = Payroll::create(array_merge($calculationPayload, [
                    'payroll_number' => $this->makePayrollNumber($employeeId, $start, $end),
                    'employee_id' => $employeeId,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                    'payment_date' => now()->toDateString(),
                    'status' => 'calculated',
                    'calculated_by' => auth()->id(),
                    'calculated_at' => now(),
                    'notes' => $notes,
                ]));
            }

            // ⭐ FIX: When the caller explicitly requested zero deductions
            //    (skip_deductions OR deductions=null), purge EVERY existing
            //    deduction PayrollItem row — including stale manual-deduction
            //    rows from a previous run. Otherwise the model's item-based
            //    accessors would revive them and inflate `total_deductions`.
            $skipDeductionsForPurge = (bool) ($options['skip_deductions'] ?? false);
            $callerPassedNullDeductions = array_key_exists('deductions', $options)
                && $options['deductions'] === null;

            if ($skipDeductionsForPurge || $callerPassedNullDeductions) {
                PayrollItem::where('payroll_id', $payroll->payroll_id)
                    ->where('item_type', 'deduction')
                    ->delete();

                $this->createSystemItems($payroll, $calculation);

                return $payroll->fresh([
                    'employee.person',
                    'employee.department',
                    'employee.position.salaryGrade',
                    'items',
                ]);
            }

            $manualDeductions = PayrollItem::where('payroll_id', $payroll->payroll_id)
                ->where('item_type', 'deduction')
                ->where('item_name', 'like', '%Manual Deduction%')
                ->get();

            PayrollItem::where('payroll_id', $payroll->payroll_id)
                ->where(function ($query) {
                    $query->where('description', 'like', 'system:%')
                        ->orWhereIn('item_name', [
                            'Regular Hours',
                            'Overtime Hours',
                            'Hourly Rate',
                            'Regular Pay',
                            'Overtime Pay',
                            'SSS',
                            'PhilHealth',
                            'Pag-IBIG',
                            'Withholding Tax',
                            'Other Deduction',
                        ]);
                })
                ->delete();

            $this->createSystemItems($payroll, $calculation);

            // Re-save manual deductions only when deductions were NOT skipped.
            foreach ($manualDeductions as $manual) {
                PayrollItem::updateOrCreate(
                    [
                        'payroll_id' => $payroll->payroll_id,
                        'item_type' => 'deduction',
                        'item_name' => $manual->item_name,
                    ],
                    [
                        'amount' => $manual->amount,
                        'description' => $manual->description,
                    ]
                );
            }
            return $payroll->fresh(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'items']);
        });
    }

    public function calculateEmployeePayroll(
        Employee $employee,
        string $start,
        string $end,
        bool $enforcePayrollReady = true,
        array $options = []
    ): array {
        $allAttendance = AttendanceLog::with(['schedule', 'overtimeRequest'])
            ->where('employee_id', $employee->employee_id)
            ->whereBetween('attendance_date', [$start, $end])
            ->orderBy('attendance_date')
            ->get()
            ->map(function (AttendanceLog $attendance) {
                if ($attendance->time_in && $attendance->time_out) {
                    return app(AttendanceService::class)->recalculate($attendance);
                }

                return $attendance;
            });

        if ($enforcePayrollReady) {
            $this->assertAttendanceReadyForPayroll($employee, $allAttendance, $start, $end);
        }

        $attendance = $allAttendance
            ->filter(fn(AttendanceLog $row) => $row->approval_status === 'approved')
            ->values();

        $regularHours = round((float) $attendance->sum('regular_hours'), 2);
        $approvedOvertime = $attendance->filter(fn($row) => (bool) $row->overtime_approved);
        $overtimeHours = round((float) $approvedOvertime->sum('overtime_hours'), 2);
        $hourlyRate = round((float) $employee->calculated_hourly_rate, 2);
        $regularPay = round($regularHours * $hourlyRate, 2);
        $overtimeRate = max(1, (float) Setting::getValue('payroll', 'overtime_rate', 1.25));
        $overtimePay = round($overtimeHours * $hourlyRate * $overtimeRate, 2);
        $grossPay = round($regularPay + $overtimePay, 2);

        // ⭐ HARDENED: Resolve deductions from the caller's explicit intent.
        //
        //   Priority:
        //   1. options['skip_deductions'] === true  → zero everywhere.
        //   2. options['auto_government_deductions'] === true
        //        → use standardDeductions(), but OVERRIDE with any
        //          explicit non-zero value from options['deductions'].
        //   3. Otherwise → use EXACTLY the values in options['deductions']
        //      (missing keys treated as 0).
        //
        //   This guarantees that opening the modal, leaving everything at 0
        //   and clicking Confirm produces a payroll with NO deductions.
        $optionsDeductions = is_array($options['deductions'] ?? null)
            ? $options['deductions']
            : [];

        $skipDeductions = (bool) ($options['skip_deductions'] ?? false);
        $autoGovernment = (bool) ($options['auto_government_deductions'] ?? false);

        if ($skipDeductions) {
            $deductions = [
                'SSS' => 0,
                'PhilHealth' => 0,
                'Pag-IBIG' => 0,
                'Withholding Tax' => 0,
                'Other Deduction' => 0,
            ];
        } else {
            $standard = $autoGovernment
                ? $this->standardDeductions($employee, $grossPay)
                : ['SSS' => 0, 'PhilHealth' => 0, 'Pag-IBIG' => 0, 'Withholding Tax' => 0];

            $deductions = [
                'SSS' => array_key_exists('sss', $optionsDeductions)
                    ? max(0, (float) $optionsDeductions['sss'])
                    : (float) ($standard['SSS'] ?? 0),
                'PhilHealth' => array_key_exists('philhealth', $optionsDeductions)
                    ? max(0, (float) $optionsDeductions['philhealth'])
                    : (float) ($standard['PhilHealth'] ?? 0),
                'Pag-IBIG' => array_key_exists('pagibig', $optionsDeductions)
                    ? max(0, (float) $optionsDeductions['pagibig'])
                    : (float) ($standard['Pag-IBIG'] ?? 0),
                'Withholding Tax' => array_key_exists('tax', $optionsDeductions)
                    ? max(0, (float) $optionsDeductions['tax'])
                    : (float) ($standard['Withholding Tax'] ?? 0),
                'Other Deduction' => array_key_exists('other', $optionsDeductions)
                    ? max(0, (float) $optionsDeductions['other'])
                    : 0,
            ];
        }

        $totalDeductions = round(array_sum($deductions), 2);

        return [
            'attendance_count' => $attendance->count(),
            'regular_hours' => $regularHours,
            'overtime_hours' => $overtimeHours,
            'total_hours' => round($regularHours + $overtimeHours, 2),
            'hourly_rate' => $hourlyRate,
            'regular_pay' => $regularPay,
            'overtime_pay' => $overtimePay,
            'overtime_rate' => $overtimeRate,
            'gross_pay' => $grossPay,
            'sss_deduction' => $deductions['SSS'],
            'philhealth_deduction' => $deductions['PhilHealth'],
            'pagibig_deduction' => $deductions['Pag-IBIG'],
            'withholding_tax' => $deductions['Withholding Tax'],
            'other_deduction' => $deductions['Other Deduction'],
            'total_deductions' => $totalDeductions,
            'net_pay' => round($grossPay - $totalDeductions, 2),
            'attendance_days' => $this->attendanceDays($attendance),
        ];
    }

    private function assertAttendanceReadyForPayroll(Employee $employee, Collection $attendance, string $start, string $end): void
    {
        if ($attendance->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_ids' => "{$employee->full_name} has no attendance between {$start} and {$end}.",
            ]);
        }

        $incomplete = $attendance->first(fn(AttendanceLog $row) => $row->status !== 'absent' && (! $row->time_in || ! $row->time_out));
        if ($incomplete) {
            $missingPart = ! $incomplete->time_in ? 'time-in' : 'time-out';
            throw ValidationException::withMessages([
                'employee_ids' => "{$employee->full_name} has a missing {$missingPart} on {$incomplete->attendance_date?->toDateString()}.",
            ]);
        }

        $pending = $attendance->first(fn(AttendanceLog $row) => $row->approval_status === 'pending');
        if ($pending) {
            throw ValidationException::withMessages([
                'employee_ids' => "{$employee->full_name} has attendance awaiting a decision on {$pending->attendance_date?->toDateString()}.",
            ]);
        }

        $notFinalized = $attendance->first(fn(AttendanceLog $row) => ! $row->payroll_ready_at);
        if ($notFinalized) {
            throw ValidationException::withMessages([
                'employee_ids' => "{$employee->full_name}'s attendance has not been saved as payroll ready for {$start} to {$end}.",
            ]);
        }

        $unresolvedOvertime = $attendance->first(function (AttendanceLog $row) {
            if ((float) $row->overtime_hours <= 0 || (bool) $row->overtime_approved) {
                return false;
            }

            return ! in_array($row->overtimeRequest?->status, ['approved', 'rejected'], true);
        });

        if ($unresolvedOvertime) {
            throw ValidationException::withMessages([
                'employee_ids' => "{$employee->full_name} still has unresolved overtime on {$unresolvedOvertime->attendance_date?->toDateString()}.",
            ]);
        }
    }

    private function createSystemItems(Payroll $payroll, array $calculation): void
    {
        $items = [
            ['earning', 'Regular Hours', $calculation['regular_hours'], 'system:hours'],
            ['earning', 'Overtime Hours', $calculation['overtime_hours'], 'system:hours'],
            ['earning', 'Hourly Rate', $calculation['hourly_rate'], 'system:rate'],
            ['earning', 'Regular Pay', $calculation['regular_pay'], 'system:earning'],
            ['earning', 'Overtime Pay', $calculation['overtime_pay'], 'system:earning'],
            ['deduction', 'SSS', $calculation['sss_deduction'] ?? 0, 'system:deduction'],
            ['deduction', 'PhilHealth', $calculation['philhealth_deduction'] ?? 0, 'system:deduction'],
            ['deduction', 'Pag-IBIG', $calculation['pagibig_deduction'] ?? 0, 'system:deduction'],
            ['deduction', 'Withholding Tax', $calculation['withholding_tax'] ?? 0, 'system:deduction'],
            ['deduction', 'Other Deduction', $calculation['other_deduction'] ?? 0, 'system:deduction'],
        ];

        foreach ($items as [$type, $name, $amount, $description]) {
            $amount = round((float) $amount, 2);
            $isInformational = in_array($name, ['Regular Hours', 'Overtime Hours', 'Hourly Rate'], true);

            // ⭐ HARDENED: Deduction rows with a zero amount are never written.
            if ($type === 'deduction' && $amount <= 0) {
                continue;
            }

            if ($amount <= 0 && ! $isInformational) {
                continue;
            }

            PayrollItem::create([
                'payroll_id' => $payroll->payroll_id,
                'item_type' => $type,
                'item_name' => $name,
                'amount' => $amount,
                'description' => $description,
            ]);
        }
    }

    private function standardDeductions(Employee $employee, float $grossPay): array
    {
        $employeeType = strtolower((string) ($employee->employee_type ?? 'full_time'));
        $eligible = in_array($employeeType, ['regular', 'full_time', 'full-time'], true);

        if (! $eligible || $grossPay <= 0) {
            return ['SSS' => 0, 'PhilHealth' => 0, 'Pag-IBIG' => 0, 'Withholding Tax' => 0];
        }

        $sssRate = (float) Setting::getValue('payroll', 'sss_employee_rate', 0.045);
        $philHealthRate = (float) Setting::getValue('payroll', 'philhealth_employee_rate', 0.025);
        $pagIbigRate = (float) Setting::getValue('payroll', 'pagibig_employee_rate', 0.02);
        $taxThreshold = (float) Setting::getValue('payroll', 'withholding_tax_threshold', 10417);
        $taxRate = (float) Setting::getValue('payroll', 'withholding_tax_rate', 0.10);

        return [
            'SSS' => min((float) Setting::getValue('payroll', 'sss_cutoff_cap', 200), round($grossPay * $sssRate, 2)),
            'PhilHealth' => min((float) Setting::getValue('payroll', 'philhealth_cutoff_cap', 150), round($grossPay * $philHealthRate, 2)),
            'Pag-IBIG' => min((float) Setting::getValue('payroll', 'pagibig_cutoff_cap', 100), round($grossPay * $pagIbigRate, 2)),
            'Withholding Tax' => $grossPay > $taxThreshold ? round(($grossPay - $taxThreshold) * $taxRate, 2) : 0,
        ];
    }

    private function attendanceDays(Collection $attendance): array
    {
        return $attendance->map(fn($row) => [
            'date' => $row->attendance_date?->toDateString(),
            'day' => $row->attendance_date?->format('D'),
            'schedule_time' => $row->schedule ? trim(($row->schedule->start_time ?? '') . ' - ' . ($row->schedule->end_time ?? '')) : 'Unscheduled',
            'time_in' => $row->formatted_time_in,
            'time_out' => $row->formatted_time_out,
            'regular_hours' => (float) $row->regular_hours,
            'overtime_hours' => (float) ((bool) $row->overtime_approved ? $row->overtime_hours : 0),
            'total_hours' => (float) $row->regular_hours + ((bool) $row->overtime_approved ? (float) $row->overtime_hours : 0),
            'late_undertime' => trim($row->late_minutes . ' min late / ' . $row->undertime_minutes . ' min undertime'),
        ])->values()->all();
    }

    private function makePayrollNumber(int $employeeId, string $start, string $end): string
    {
        $lastNumber = Payroll::withTrashed()
            ->where('payroll_number', 'like', 'PR-%')
            ->lockForUpdate()
            ->pluck('payroll_number')
            ->map(function ($number) {
                return preg_match('/^PR-(\d+)$/', (string) $number, $matches) ? (int) $matches[1] : 0;
            })
            ->max();

        return 'PR-' . str_pad((string) ((int) $lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }
}
