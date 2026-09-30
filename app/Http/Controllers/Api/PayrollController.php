<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Setting;
use App\Services\NotificationService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $payrolls = $this->payrollQuery($request)
            ->latest('payroll_id')
            ->paginate($request->integer('per_page', 15));

        return $this->ok($payrolls);
    }

    public function history(Request $request)
    {
        $payrolls = $this->payrollQuery($request, true)
            ->latest('deleted_at')
            ->paginate($request->integer('per_page', 15));

        return $this->ok($payrolls);
    }

    public function stats(Request $request)
    {
        $payrolls = $this->payrollQuery($request)->get();

        return $this->ok(['statistics' => $this->statisticsFor($payrolls)]);
    }

    public function historyStats(Request $request)
    {
        $payrolls = $this->payrollQuery($request, true)->get();
        $statistics = $this->statisticsFor($payrolls);
        $statistics['total_history'] = $payrolls->count();
        $statistics['total_history_amount'] = round((float) $payrolls->sum('net_pay'), 2);
        $statistics['paid_history_count'] = $payrolls->where('status', 'paid')->count();
        $statistics['affected_employees'] = $statistics['total_employees'] ?? 0;
        $statistics['total_deleted'] = $statistics['total_history'];
        $statistics['total_deleted_amount'] = $statistics['total_history_amount'];

        return $this->ok(['statistics' => $statistics]);
    }

    public function preview(Request $request, PayrollService $service)
    {
        [$start, $end] = $this->validatedPeriod($request);

        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,employee_id',
        ]);

        return $this->ok($service->previewMany($data['employee_ids'], $start, $end), 'Payroll preview generated');
    }

    public function process(Request $request, PayrollService $service)
    {
        [$start, $end] = $this->validatedPeriod($request);

        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|distinct|exists:employees,employee_id',
            'notes' => 'nullable|string',
        ]);

        $payrolls = collect();
        $skipped = collect();

        foreach (collect($data['employee_ids'])->unique() as $employeeId) {
            try {
                $payroll = DB::transaction(fn() => $service->generate(
                    (int) $employeeId,
                    $start,
                    $end,
                    $data['notes'] ?? null
                ));
                $payrolls->push($payroll);
                AuditLog::log('payroll_processed', AuditLog::MODULE_PAYROLL, $payroll->payroll_id, null, $payroll->getAttributes());
            } catch (ValidationException $exception) {
                $skipped->push([
                    'employee_id' => (int) $employeeId,
                    'reason' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
                $skipped->push([
                    'employee_id' => (int) $employeeId,
                    'reason' => 'Payroll processing failed for this employee. Review the attendance record and try again.',
                ]);
            }
        }

        foreach ($payrolls as $payroll) {
            try {
                $this->notificationService->payrollReadyForProcessing($payroll);
            } catch (\Throwable) {
                // Notifications should not break payroll generation.
            }
        }

        $message = $payrolls->isNotEmpty()
            ? "{$payrolls->count()} payroll record(s) processed successfully."
            : 'No payroll records were processed. Review the skipped employee reasons.';

        return $this->ok([
            'payrolls' => $payrolls->values(),
            'processed_count' => $payrolls->count(),
            'skipped_count' => $skipped->count(),
            'skipped' => $skipped->values(),
        ], $message);
    }

    public function show(Payroll $payroll)
    {
        return $this->ok($this->loadPayroll($payroll));
    }

    public function update(Request $request, Payroll $payroll)
    {
        $oldValues = $payroll->getAttributes();
        if ($payroll->status === 'paid') {
            throw ValidationException::withMessages(['status' => 'Paid payroll records are immutable.']);
        }
        $data = $request->validate([
            'status' => 'nullable|in:draft,calculated,cancelled',
            'notes' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'manual_deductions' => 'nullable|numeric|min:0',
            'manual_deduction_notes' => 'nullable|string',
            'deduction_type' => 'nullable|string|max:100',
            'deduction_category' => 'nullable|string|max:100',
            'deduction_reference' => 'nullable|string|max:100',
            'deduction_date' => 'nullable|date',
            'deduction_approved_by' => 'nullable|string|max:255',
            'sss_deduction' => 'nullable|numeric|min:0',
            'pagibig_deduction' => 'nullable|numeric|min:0',
            'philhealth_deduction' => 'nullable|numeric|min:0',
            'other_deduction' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($payroll, $data) {
            $payrollUpdates = collect($data)
                ->only(['status', 'notes', 'payment_date'])
                ->filter(static fn($value) => $value !== null)
                ->all();

            if (! empty($payrollUpdates)) {
                $payroll->update($payrollUpdates);
            }

            if (array_key_exists('manual_deductions', $data)) {
                $this->upsertManualDeduction($payroll, $data);
            }
            foreach (
                [
                    'sss_deduction' => 'SSS',
                    'pagibig_deduction' => 'Pag-IBIG',
                    'philhealth_deduction' => 'PhilHealth',
                    'other_deduction' => 'Other Deduction',
                ] as $field => $itemName
            ) {
                if (array_key_exists($field, $data)) {
                    $this->upsertNamedDeduction($payroll, $itemName, (float) $data[$field]);
                }
            }
        });
        AuditLog::log('payroll_edited', AuditLog::MODULE_PAYROLL, $payroll->payroll_id, $oldValues, $payroll->fresh()->getAttributes());

        return $this->ok($this->loadPayroll($payroll->fresh()), 'Payroll updated');
    }

    public function bulkDeductions(Request $request)
    {
        $data = $request->validate([
            'payroll_ids' => 'required|array|min:1',
            'payroll_ids.*' => 'integer|exists:payrolls,payroll_id',
            'manual_deductions' => 'required|numeric|min:0',
            'manual_deduction_notes' => 'nullable|string',
            'deduction_type' => 'nullable|string|max:100',
            'deduction_category' => 'nullable|string|max:100',
            'deduction_reference' => 'nullable|string|max:100',
            'deduction_date' => 'nullable|date',
            'deduction_approved_by' => 'nullable|string|max:255',
        ]);

        $payrolls = Payroll::whereIn('payroll_id', $data['payroll_ids'])->get();

        DB::transaction(function () use ($payrolls, $data) {
            foreach ($payrolls as $payroll) {
                $this->upsertManualDeduction($payroll, $data);
            }
        });

        return $this->ok($payrolls->map(fn($payroll) => $this->loadPayroll($payroll->fresh())), 'Payroll deductions updated');
    }

    public function approve(Payroll $payroll)
    {
        $oldValues = $payroll->getAttributes();
        if ($payroll->status === 'paid') {
            throw ValidationException::withMessages(['status' => 'Paid payroll records cannot be approved again.']);
        }
        if ($payroll->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Cancelled payroll records cannot be approved.']);
        }
        if ($payroll->status === 'approved') {
            return $this->ok($this->loadPayroll($payroll), 'Payroll is already approved');
        }

        $payroll->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        AuditLog::log('payroll_approved', AuditLog::MODULE_PAYROLL, $payroll->payroll_id, $oldValues, $payroll->fresh()->getAttributes());

        $employee = $payroll->employee;
        if ($employee && $employee->user_id) {
            try {
                $this->notificationService->notifyUser(
                    $employee->user_id,
                    'payroll_approved',
                    'Payroll Approved',
                    "Your payroll for period {$payroll->cutoff_start?->format('M d')} - {$payroll->cutoff_end?->format('M d, Y')} has been approved.",
                    Notification::PRIORITY_MEDIUM,
                    ['payroll_id' => $payroll->payroll_id]
                );
            } catch (\Throwable) {
                // Do not block approval when notification delivery fails.
            }
        }

        return $this->ok($this->loadPayroll($payroll->fresh()), 'Payroll approved');
    }

    public function markPaid(Request $request, Payroll $payroll)
    {
        $data = $request->validate([
            'payment_date' => 'nullable|date',
        ]);

        $paidPayroll = DB::transaction(function () use ($payroll, $data) {
            $lockedPayroll = Payroll::query()
                ->whereKey($payroll->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayroll->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => 'Approve the payroll first before marking it as paid.',
                ]);
            }

            $lockedPayroll->update([
                'status' => 'paid',
                'paid_by' => auth()->id(),
                'paid_at' => now(),
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            ]);

            $loaded = $this->loadPayroll($lockedPayroll->fresh());
            AuditLog::log('payroll_marked_paid', AuditLog::MODULE_PAYROLL, $lockedPayroll->payroll_id, ['status' => 'approved'], $lockedPayroll->getAttributes());
            $lockedPayroll->delete();

            return $loaded;
        });

        $employee = $paidPayroll->employee;
        if ($employee && $employee->user_id) {
            try {
                $this->notificationService->notifyUser(
                    $employee->user_id,
                    'payroll_paid',
                    'Payroll Payment Received',
                    'Your payroll payment of ₱' . number_format($paidPayroll->net_pay, 2) . " for period {$paidPayroll->cutoff_start?->format('M d')} - {$paidPayroll->cutoff_end?->format('M d, Y')} has been processed.",
                    Notification::PRIORITY_HIGH,
                    ['payroll_id' => $paidPayroll->payroll_id, 'amount' => $paidPayroll->net_pay]
                );
            } catch (\Throwable) {
                // Do not block paid marking when notification delivery fails.
            }
        }

        return $this->ok($paidPayroll, 'Payroll marked as paid and moved to history');
    }

    public function destroy(Payroll $payroll)
    {
        $payroll->delete();

        return $this->ok(null, 'Payroll archived');
    }

    public function restore($id)
    {
        $payroll = Payroll::onlyTrashed()->findOrFail($id);
        if ($payroll->status === 'paid') {
            throw ValidationException::withMessages([
                'status' => 'Paid payroll history is final and cannot be restored to the active payroll table.',
            ]);
        }
        $payroll->restore();

        return $this->ok($this->loadPayroll($payroll->fresh()), 'Payroll restored');
    }

    public function permanentDelete($id)
    {
        $payroll = Payroll::onlyTrashed()->findOrFail($id);
        $payroll->items()->delete();
        $payroll->forceDelete();

        return $this->ok(null, 'Payroll permanently deleted');
    }

    public function payslip(Payroll $payroll)
    {
        $payroll = $this->loadPayroll($payroll);

        // Build the per-day attendance rows for this cutoff.
        $attendanceDays = AttendanceLog::with(['schedule'])
            ->where('employee_id', $payroll->employee_id)
            ->where('approval_status', 'approved')
            ->whereBetween('attendance_date', [$payroll->cutoff_start, $payroll->cutoff_end])
            ->orderBy('attendance_date')
            ->get()
            ->map(fn(AttendanceLog $row) => [
                'attendance_id' => $row->attendance_id,
                'date' => $row->attendance_date?->toDateString(),
                'day' => $row->attendance_date?->format('D'),
                'schedule_time' => $row->schedule_time,
                'time_in' => $row->formatted_time_in ?: 'No time in',
                'time_out' => $row->formatted_time_out ?: 'No time out',
                'time_in_selfie_url' => $row->time_in_selfie_url,
                'time_out_selfie_url' => $row->time_out_selfie_url,
                'regular_hours' => (float) $row->regular_hours,
                'overtime_hours' => (float) ((bool) $row->overtime_approved ? $row->overtime_hours : 0),
                'total_hours' => (float) $row->regular_hours + ((bool) $row->overtime_approved ? (float) $row->overtime_hours : 0),
                'late_undertime' => trim($row->late_minutes . ' min late / ' . $row->undertime_minutes . ' min undertime'),
            ])
            ->values();

        // ---- HOURLY RATE RESOLUTION ---------------------------------------
        // Prefer the payroll row's stored rate, but fall back to the
        // employee's own rate and then the salary grade default. This
        // prevents the work-detail column from rendering ₱0.00 when the
        // payroll row has no rate stored.
        $hourlyRate = (float) $payroll->hourly_rate;
        if ($hourlyRate <= 0) {
            $hourlyRate = (float) (
                $payroll->employee?->hourly_rate
                ?: $payroll->employee?->position?->salaryGrade?->default_hourly_rate
                ?: 0
            );
        }
        $overtimeRate = round($hourlyRate * 1.25, 2);

        // Enrich each day with the computed pay columns the payslip reads.
        $workDetails = $attendanceDays->map(function ($row) use ($hourlyRate, $overtimeRate) {
            $regularPay = round((float) $row['regular_hours'] * $hourlyRate, 2);
            $overtimePay = round((float) $row['overtime_hours'] * $overtimeRate, 2);

            return array_merge($row, [
                'hourly_rate' => $hourlyRate,
                'overtime_rate' => $overtimeRate,
                'regular_pay' => $regularPay,
                'overtime_pay' => $overtimePay,
                'daily_total' => round($regularPay + $overtimePay, 2),
            ]);
        })->values();

        $undertimeHours = round((float) AttendanceLog::where('employee_id', $payroll->employee_id)
            ->whereBetween('attendance_date', [$payroll->cutoff_start, $payroll->cutoff_end])
            ->sum('undertime_hours'), 2);

        return $this->ok([
            'payroll' => $payroll,
            'attendance_days' => $attendanceDays,
            // Top-level work_details used by the landscape React payslip.
            'work_details' => $workDetails,
            'summary' => [
                'company_name' => Setting::getValue('company', 'name', "Dear Babs's Fastfood and Catering Services"),
                'company_address' => Setting::getValue('company', 'address', 'Zone 3 Amoros, El Salvador City, Misamis Oriental'),
                'payroll_number' => $payroll->payroll_number,
                'payroll_id' => $payroll->payroll_id,
                'employee_name' => $payroll->employee_name,
                'employee_code' => $payroll->employee_code,
                'department_name' => $payroll->department_name,
                'position_name' => $payroll->position_name,
                'employee_type' => $payroll->employee?->employee_type ?? $payroll->employee?->employment_type,
                'bank_account_number' => $payroll->employee?->bank_account_number
                    ?? $payroll->employee?->bank_account
                    ?? null,
                'period_start' => $payroll->cutoff_start?->toDateString(),
                'period_end' => $payroll->cutoff_end?->toDateString(),
                'payment_date' => $payroll->payment_date?->toDateString(),
                'status' => $payroll->status,
                'regular_hours' => $payroll->regular_hours,
                'overtime_hours' => $payroll->overtime_hours,
                'undertime_hours' => $undertimeHours,
                'hourly_rate' => $hourlyRate,
                'overtime_rate' => $overtimeRate,
                'total_hours' => $payroll->total_hours,
                'regular_pay' => $payroll->regular_pay,
                'overtime_pay' => $payroll->overtime_pay,
                'holiday_pay' => 0,
                'gross_pay' => $payroll->gross_pay,
                'total_deductions' => $payroll->total_deductions,
                'sss' => $payroll->sss_deduction,
                'pagibig' => $payroll->pagibig_deduction,
                'philhealth' => $payroll->philhealth_deduction,
                'tax' => $payroll->withholding_tax ?? $payroll->tax_deduction ?? 0,
                'other_deduction' => $payroll->other_deductions,
                'taxable_income' => round((float) $payroll->gross_pay, 2),
                'net_pay' => $payroll->net_pay,
                'work_details_totals' => [
                    'total_regular_hours' => round((float) $attendanceDays->sum('regular_hours'), 2),
                    'total_overtime_hours' => round((float) $attendanceDays->sum('overtime_hours'), 2),
                    'total_regular_pay' => round((float) $workDetails->sum('regular_pay'), 2),
                    'total_overtime_pay' => round((float) $workDetails->sum('overtime_pay'), 2),
                    'total_labor_cost' => round((float) $workDetails->sum('daily_total'), 2),
                ],
            ],
        ], 'Payslip generated');
    }

    private function payrollQuery(Request $request, bool $onlyTrashed = false)
    {
        $query = Payroll::with(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'items']);

        if ($onlyTrashed) {
            $query->onlyTrashed();
        }

        [$start, $end] = $this->periodFromRequest($request);

        if ($start) {
            $query->whereDate('cutoff_end', '>=', $start);
        }

        if ($end) {
            $query->whereDate('cutoff_start', '<=', $end);
        }

        if ($request->filled('department_id') && $request->input('department_id') !== 'all') {
            $query->whereHas('employee', fn($employeeQuery) => $employeeQuery->where('department_id', $request->input('department_id')));
        }

        if ($request->filled('employee_id') && $request->input('employee_id') !== 'all') {
            $query->where('employee_id', $request->input('employee_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = strtolower((string) $request->input('status'));
            if ($status === 'pending') {
                $query->whereIn('status', ['draft', 'calculated']);
            } elseif ($status === 'processing') {
                $query->whereIn('status', ['calculated', 'approved']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($inner) use ($search) {
                $inner->where('payroll_number', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn($employeeQuery) => $employeeQuery->where('employee_code', 'like', "%{$search}%"))
                    ->orWhereHas('employee.person', function ($personQuery) use ($search) {
                        $personQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function validatedPeriod(Request $request): array
    {
        $request->merge([
            'start_date' => $request->input('start_date', $request->input('period_start')),
            'end_date' => $request->input('end_date', $request->input('period_end')),
        ]);

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        return [$validated['start_date'], $validated['end_date']];
    }

    private function periodFromRequest(Request $request): array
    {
        return [
            $request->input('start_date', $request->input('period_start')),
            $request->input('end_date', $request->input('period_end')),
        ];
    }

    private function statisticsFor($payrolls): array
    {
        return [
            'total_payrolls' => $payrolls->count(),
            'total_employees' => $payrolls->pluck('employee_id')->unique()->count(),
            'total_payroll_amount' => round((float) $payrolls->sum('net_pay'), 2),
            'total_gross_pay' => round((float) $payrolls->sum('gross_pay'), 2),
            'total_deductions' => round((float) $payrolls->sum('total_deductions'), 2),
            'average_net_pay' => $payrolls->count() ? round((float) $payrolls->avg('net_pay'), 2) : 0,
            'regular_hours' => round((float) $payrolls->sum('regular_hours'), 2),
            'overtime_hours' => round((float) $payrolls->sum('overtime_hours'), 2),
            'pending_count' => $payrolls->whereIn('status', ['draft', 'calculated'])->count(),
            'approved_count' => $payrolls->where('status', 'approved')->count(),
            'paid_count' => $payrolls->where('status', 'paid')->count(),
        ];
    }

    private function upsertManualDeduction(Payroll $payroll, array $data): void
    {
        $amount = round((float) ($data['manual_deductions'] ?? 0), 2);
        $type = $data['deduction_type'] ?? 'Manual';
        $itemName = 'Manual Deduction - ' . $type;

        if ($amount <= 0) {
            PayrollItem::where('payroll_id', $payroll->payroll_id)
                ->where('item_type', 'deduction')
                ->where('item_name', 'like', 'Manual Deduction%')
                ->delete();
            return;
        }

        PayrollItem::where('payroll_id', $payroll->payroll_id)
            ->where('item_type', 'deduction')
            ->where('item_name', 'like', 'Manual Deduction%')
            ->where('item_name', '!=', $itemName)
            ->delete();

        PayrollItem::updateOrCreate(
            [
                'payroll_id' => $payroll->payroll_id,
                'item_type' => 'deduction',
                'item_name' => $itemName,
            ],
            [
                'amount' => $amount,
                'description' => json_encode([
                    'notes' => $data['manual_deduction_notes'] ?? null,
                    'deduction_type' => $type,
                    'deduction_category' => $data['deduction_category'] ?? null,
                    'deduction_reference' => $data['deduction_reference'] ?? null,
                    'deduction_date' => $data['deduction_date'] ?? now()->toDateString(),
                    'deduction_approved_by' => $data['deduction_approved_by'] ?? optional(auth()->user())->username,
                    'status' => 'approved',
                    'source' => 'manual_payroll_adjustment',
                ]),
            ]
        );
    }

    private function upsertNamedDeduction(Payroll $payroll, string $name, float $amount): void
    {
        if ($amount <= 0) {
            PayrollItem::where('payroll_id', $payroll->payroll_id)
                ->where('item_type', 'deduction')
                ->where('item_name', $name)
                ->delete();
            return;
        }

        PayrollItem::updateOrCreate(
            ['payroll_id' => $payroll->payroll_id, 'item_type' => 'deduction', 'item_name' => $name],
            ['amount' => round($amount, 2), 'description' => 'admin:deduction-adjustment']
        );
    }

    private function loadPayroll(Payroll $payroll): Payroll
    {
        return $payroll->load(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'items']);
    }
}