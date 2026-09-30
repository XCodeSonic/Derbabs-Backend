<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()->orderBy('employee_id')->get();
        $userId = User::query()->value('user_id');

        if ($employees->isEmpty()) {
            $this->command?->warn('No employees found. Please run EmployeeSeeder first.');
            return;
        }

        $this->command?->info('Creating idempotent historical payroll data for the previous six completed months...');
        $this->archiveLegacyDemoPayrolls();

        for ($monthsAgo = 6; $monthsAgo >= 1; $monthsAgo--) {
            $month = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $cutoffs = [
                [$month->copy()->startOfMonth(), $month->copy()->day(15)],
                [$month->copy()->day(16), $month->copy()->endOfMonth()],
            ];

            foreach ($cutoffs as $cutoffIndex => [$start, $end]) {
                foreach ($employees as $employeeIndex => $employee) {
                    DB::transaction(function () use ($employee, $employeeIndex, $cutoffIndex, $start, $end, $userId): void {
                        $existing = Payroll::withTrashed()
                            ->where('employee_id', $employee->employee_id)
                            ->whereDate('cutoff_start', $start->toDateString())
                            ->whereDate('cutoff_end', $end->toDateString())
                            ->first();

                        // Never overwrite a real payroll that happens to use the same historical cutoff.
                        if ($existing && ! str_starts_with((string) $existing->payroll_number, 'SEED-PR-')) {
                            return;
                        }

                        $regularHours = 72 + (($employeeIndex + $cutoffIndex) % 9);
                        $overtimeHours = (($employeeIndex + $cutoffIndex) % 4) * 1.5;
                        $hourlyRate = max(75, round((float) ($employee->calculated_hourly_rate ?: $employee->hourly_rate ?: 100), 2));
                        $regularPay = round($regularHours * $hourlyRate, 2);
                        $overtimePay = round($overtimeHours * $hourlyRate * 1.25, 2);
                        $grossPay = round($regularPay + $overtimePay, 2);
                        $sss = min(200, round($grossPay * 0.045, 2));
                        $philHealth = min(150, round($grossPay * 0.025, 2));
                        $pagIbig = min(100, round($grossPay * 0.02, 2));
                        $paidAt = $end->copy()->addDays(5)->setTime(10, 0);
                        $payrollNumber = sprintf(
                            'SEED-PR-%s-%s-%04d',
                            $start->format('Ymd'),
                            $end->format('Ymd'),
                            $employee->employee_id
                        );

                        $payroll = $existing ?: new Payroll();
                        $payroll->forceFill([
                            'payroll_number' => $payrollNumber,
                            'employee_id' => $employee->employee_id,
                            'cutoff_start' => $start->toDateString(),
                            'cutoff_end' => $end->toDateString(),
                            'payment_date' => $paidAt->toDateString(),
                            'status' => 'paid',
                            'calculated_by' => $userId,
                            'calculated_at' => $end->copy()->subDays(2),
                            'approved_by' => $userId,
                            'approved_at' => $end->copy()->addDay(),
                            'paid_by' => $userId,
                            'paid_at' => $paidAt,
                            'notes' => 'Historical dashboard seed data. Safe to rerun.',
                            'created_at' => $end->copy()->subDays(2),
                            'updated_at' => $paidAt,
                            'deleted_at' => null,
                        ])->save();

                        PayrollItem::where('payroll_id', $payroll->payroll_id)->delete();

                        $items = [
                            ['earning', 'Regular Hours', $regularHours, 'seed:historical-dashboard'],
                            ['earning', 'Overtime Hours', $overtimeHours, 'seed:historical-dashboard'],
                            ['earning', 'Hourly Rate', $hourlyRate, 'seed:historical-dashboard'],
                            ['earning', 'Regular Pay', $regularPay, 'seed:historical-dashboard'],
                            ['earning', 'Overtime Pay', $overtimePay, 'seed:historical-dashboard'],
                            ['deduction', 'SSS', $sss, 'seed:historical-dashboard'],
                            ['deduction', 'PhilHealth', $philHealth, 'seed:historical-dashboard'],
                            ['deduction', 'Pag-IBIG', $pagIbig, 'seed:historical-dashboard'],
                        ];

                        foreach ($items as [$type, $name, $amount, $description]) {
                            PayrollItem::create([
                                'payroll_id' => $payroll->payroll_id,
                                'item_type' => $type,
                                'item_name' => $name,
                                'amount' => $amount,
                                'description' => $description,
                                'created_at' => $end->copy()->subDays(2),
                                'updated_at' => $paidAt,
                            ]);
                        }

                        // Paid payroll belongs in history, matching the live payment workflow.
                        $payroll->forceFill(['deleted_at' => $paidAt])->saveQuietly();
                    });
                }
            }
        }

        $this->command?->info('Historical payroll charts and Payroll History seed data are ready.');
    }

    private function archiveLegacyDemoPayrolls(): void
    {
        Payroll::query()
            ->where('status', 'paid')
            ->whereNull('deleted_at')
            ->where('payroll_number', 'like', 'PR-%')
            ->get()
            ->each(function (Payroll $payroll): void {
                // The original demo seeder used PR-YYYYMMDD-###. Live payrolls use
                // PR-YYYYMMDD-YYYYMMDD-####, so real records are not matched.
                if (! preg_match('/^PR-\d{8}-\d{3}$/', (string) $payroll->payroll_number)) {
                    return;
                }

                $archiveAt = $payroll->paid_at
                    ?: ($payroll->payment_date?->copy()->setTime(10, 0))
                    ?: $payroll->updated_at
                    ?: now();

                $payroll->forceFill(['deleted_at' => $archiveAt])->saveQuietly();
            });
    }
}
