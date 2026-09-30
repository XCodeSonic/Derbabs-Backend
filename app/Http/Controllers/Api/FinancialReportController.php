<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\PayrollItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) ($request->year ?? now()->year);
        $monthlyReports = [];

        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            $monthlyReports[] = $this->generateMonthlyReport($startDate, $endDate, $month);
        }

        $totalRevenue = Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->sum('total_amount');
        $totalCollected = Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->sum('paid_amount');
        $totalExpenses = $this->getTotalExpenses($year);

        $summary = [
            'total_revenue' => round((float) $totalRevenue, 2),
            'total_collected' => round((float) $totalCollected, 2),
            'total_outstanding' => round((float) Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->sum(DB::raw('GREATEST(total_amount - paid_amount, 0)')), 2),
            'total_expenses' => round((float) $totalExpenses, 2),
            'total_profit' => round((float) $totalRevenue - (float) $totalExpenses, 2),
            'collection_rate' => $this->calculateCollectionRate($year),
            'overdue_amount' => round((float) Invoice::where('due_date', '<', now())->whereRaw('paid_amount < total_amount')->sum(DB::raw('GREATEST(total_amount - paid_amount, 0)')), 2),
            'deposit_collection_rate' => $this->calculateDepositCollectionRate(),
        ];

        if ($request->boolean('monthly')) {
            return $this->ok($monthlyReports);
        }

        return $this->ok([
            'summary' => $summary,
            'monthly' => $monthlyReports,
            'year' => $year,
        ]);
    }

    public function sales(Request $request)
    {
        $startDate = $request->start_date && $request->start_date !== 'N/A'
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();
        $endDate = $request->end_date && $request->end_date !== 'N/A'
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $sales = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('SUM(total_amount) as total_sales')
            ->selectRaw('SUM(paid_amount) as collected_amount')
            ->selectRaw('SUM(GREATEST(total_amount - paid_amount, 0)) as outstanding')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $byPaymentMethod = BookingPayment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', 'completed')
            ->select('payment_method')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("SUM(CASE WHEN payment_type = 'refund' THEN -ABS(amount) ELSE amount END) as total")
            ->groupBy('payment_method')
            ->get();

        return $this->ok([
            'summary' => [
                'total_sales' => round((float) $sales->sum('total_sales'), 2),
                'total_collected' => round((float) $sales->sum('collected_amount'), 2),
                'total_outstanding' => round((float) $sales->sum('outstanding'), 2),
                'average_invoice' => $sales->sum('invoice_count') > 0 ? round((float) $sales->sum('total_sales') / max(1, (int) $sales->sum('invoice_count')), 2) : 0,
                'period' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
                'by_payment_method' => $byPaymentMethod,
            ],
            'daily_sales' => $sales,
            'daily' => $sales->map(fn ($item) => [
                'date' => $item->date,
                'orders' => (int) $item->invoice_count,
                'total_sales' => round((float) $item->total_sales, 2),
                'revenue' => round((float) $item->collected_amount, 2),
                'outstanding' => round((float) $item->outstanding, 2),
            ]),
        ]);
    }

    public function expenses(Request $request)
    {
        $year = (int) ($request->year ?? now()->year);
        $expensesByMonth = $this->getExpensesByMonth($year);

        $monthlyExpenses = collect(range(1, 12))->map(function ($month) use ($expensesByMonth) {
            return [
                'month' => $month,
                'month_name' => Carbon::create(null, $month, 1)->format('F'),
                'inventory' => round((float) ($expensesByMonth[$month]['inventory'] ?? 0), 2),
                'payroll' => round((float) ($expensesByMonth[$month]['payroll'] ?? 0), 2),
                'operational' => 0,
                'total' => round((float) ($expensesByMonth[$month]['total'] ?? 0), 2),
            ];
        });

        return $this->ok([
            'year' => $year,
            'monthly' => $monthlyExpenses,
            'total' => round((float) $monthlyExpenses->sum('total'), 2),
            'by_category' => [
                'inventory' => round((float) $monthlyExpenses->sum('inventory'), 2),
                'payroll' => round((float) $monthlyExpenses->sum('payroll'), 2),
                'operational' => 0,
            ],
        ]);
    }

    public function profitLoss(Request $request)
    {
        $year = (int) ($request->year ?? now()->year);
        $expensesByMonth = $this->getExpensesByMonth($year);
        $revenue = Invoice::whereYear('created_at', $year)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('MONTH(created_at) as month')
            ->selectRaw('SUM(total_amount) as revenue')
            ->selectRaw('SUM(paid_amount) as collected')
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $profitLoss = collect(range(1, 12))->map(function ($month) use ($revenue, $expensesByMonth) {
            $monthRevenue = (float) ($revenue[$month]->revenue ?? 0);
            $monthCollected = (float) ($revenue[$month]->collected ?? 0);
            $monthExpenses = (float) ($expensesByMonth[$month]['total'] ?? 0);
            $profit = $monthRevenue - $monthExpenses;

            return [
                'month' => $month,
                'month_name' => Carbon::create(null, $month, 1)->format('F'),
                'revenue' => round($monthRevenue, 2),
                'collected' => round($monthCollected, 2),
                'expenses' => round($monthExpenses, 2),
                'profit' => round($profit, 2),
                'profit_margin' => $monthRevenue > 0 ? round(($profit / $monthRevenue) * 100, 2) : 0,
            ];
        });

        $totalRevenue = round((float) $profitLoss->sum('revenue'), 2);
        $totalExpenses = round((float) $profitLoss->sum('expenses'), 2);
        $totalProfit = round($totalRevenue - $totalExpenses, 2);

        return $this->ok([
            'year' => $year,
            'data' => $profitLoss,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'total_profit' => $totalProfit,
            'overall_margin' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0,
            'summary' => [
                'best_month' => $profitLoss->sortByDesc('profit')->first(),
                'worst_month' => $profitLoss->sortBy('profit')->first(),
                'average_profit' => round($totalProfit / 12, 2),
                'average_margin' => round((float) $profitLoss->avg('profit_margin'), 2),
            ],
        ]);
    }

    private function generateMonthlyReport(Carbon $startDate, Carbon $endDate, int $month): array
    {
        $revenue = Invoice::whereBetween('created_at', [$startDate, $endDate])->where('status', '!=', 'cancelled')->sum('total_amount');
        $collected = Invoice::whereBetween('created_at', [$startDate, $endDate])->where('status', '!=', 'cancelled')->sum('paid_amount');
        $inventoryCost = InventoryMovement::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('movement_type', ['purchase', 'waste'])
            ->sum(DB::raw('ABS(quantity_change) * unit_cost_at_time'));
        $payrollCost = $this->payrollEarnings($startDate, $endDate);
        $totalExpenses = (float) $inventoryCost + (float) $payrollCost;
        $profit = (float) $revenue - $totalExpenses;

        return [
            'month' => $month,
            'month_name' => $startDate->format('F'),
            'revenue' => round((float) $revenue, 2),
            'collected' => round((float) $collected, 2),
            'outstanding' => round(max(0, (float) $revenue - (float) $collected), 2),
            'inventory_costs' => round((float) $inventoryCost, 2),
            'payroll_costs' => round((float) $payrollCost, 2),
            'operational_costs' => 0,
            'total_expenses' => round($totalExpenses, 2),
            'profit' => round($profit, 2),
            'profit_margin' => $revenue > 0 ? round(($profit / (float) $revenue) * 100, 2) : 0,
            'payments_by_method' => BookingPayment::whereBetween('payment_date', [$startDate, $endDate])->where('status', 'completed')->select('payment_method', DB::raw('SUM(amount) as total'))->groupBy('payment_method')->get(),
            'invoice_count' => Invoice::whereBetween('created_at', [$startDate, $endDate])->where('status', '!=', 'cancelled')->count(),
            'collection_rate' => $revenue > 0 ? round(((float) $collected / (float) $revenue) * 100, 2) : 0,
        ];
    }

    private function getTotalExpenses(int $year): float
    {
        return collect($this->getExpensesByMonth($year))->sum('total');
    }

    private function getExpensesByMonth(int $year): array
    {
        $inventory = InventoryMovement::whereYear('created_at', $year)
            ->whereIn('movement_type', ['purchase', 'waste'])
            ->selectRaw('MONTH(created_at) as month')
            ->selectRaw('SUM(ABS(quantity_change) * unit_cost_at_time) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month')
            ->toArray();

        $payroll = DB::table('payrolls')
            ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereYear('payrolls.created_at', $year)
            ->where('payroll_items.item_type', 'earning')
            ->selectRaw('MONTH(payrolls.created_at) as month')
            ->selectRaw('SUM(payroll_items.amount) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month')
            ->toArray();

        $expenses = [];
        for ($month = 1; $month <= 12; $month++) {
            $expenses[$month] = [
                'inventory' => round((float) ($inventory[$month] ?? 0), 2),
                'payroll' => round((float) ($payroll[$month] ?? 0), 2),
                'total' => round((float) ($inventory[$month] ?? 0) + (float) ($payroll[$month] ?? 0), 2),
            ];
        }

        return $expenses;
    }

    private function payrollEarnings(Carbon $startDate, Carbon $endDate): float
    {
        return (float) DB::table('payrolls')
            ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereBetween('payrolls.created_at', [$startDate, $endDate])
            ->where('payroll_items.item_type', 'earning')
            ->sum('payroll_items.amount');
    }

    private function calculateCollectionRate(int $year): float
    {
        $total = Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->sum('total_amount');
        $paid = Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->sum('paid_amount');
        return $total <= 0 ? 0 : round(((float) $paid / (float) $total) * 100, 2);
    }

    private function calculateDepositCollectionRate(): float
    {
        $bookings = Booking::with('payments')->whereIn('booking_status', ['confirmed', 'completed'])->get();
        $totalDeposits = 0;
        $paidDeposits = 0;

        foreach ($bookings as $booking) {
            $required = (float) ($booking->required_deposit ?? 0);
            $paid = (float) $booking->payments->where('payment_type', 'deposit')->where('status', 'completed')->sum('amount');
            $totalDeposits += $required;
            $paidDeposits += min($paid, $required);
        }

        return $totalDeposits <= 0 ? 0 : round(($paidDeposits / $totalDeposits) * 100, 2);
    }
}
