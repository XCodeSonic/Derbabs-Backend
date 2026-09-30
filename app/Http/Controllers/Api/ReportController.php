<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\PayrollItem;
use App\Models\Quotation;
use App\Models\Review;
use App\Models\ServiceEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        [$startDate, $endDate] = $this->dateRange($request);

        $daily = $this->invoiceSeries($startDate, $endDate, 'DATE(service_events.event_date)', 'date');
        $weekly = $this->invoiceSeries($startDate, $endDate, "DATE_FORMAT(service_events.event_date, '%x-W%v')", 'week');
        $monthly = $this->invoiceSeries($startDate, $endDate, "DATE_FORMAT(service_events.event_date, '%Y-%m')", 'month')
            ->map(function ($row) {
                $row['month'] = Carbon::parse($row['period'] . '-01')->format('M');
                return $row;
            });

        $paymentSummary = BookingPayment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', 'completed')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -ABS(amount) ELSE amount END), 0) total_collected")
            ->selectRaw('COUNT(*) payment_count')
            ->first();

        $invoiceTotals = Invoice::query()
            ->join('bookings', 'invoices.booking_id', '=', 'bookings.booking_id')
            ->join('service_events', 'bookings.service_event_id', '=', 'service_events.service_event_id')
            ->where('invoices.status', '!=', 'cancelled')
            ->whereBetween('service_events.event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) total_sales')
            ->selectRaw('COALESCE(SUM(invoices.paid_amount), 0) total_paid')
            ->selectRaw('COALESCE(SUM(GREATEST(invoices.total_amount - invoices.paid_amount, 0)), 0) total_outstanding')
            ->selectRaw('COUNT(DISTINCT invoices.invoice_id) invoice_count')
            ->first();

        $topPackages = ServiceEvent::leftJoin('packages', 'service_events.package_id', '=', 'packages.package_id')
            ->leftJoin('bookings', 'service_events.service_event_id', '=', 'bookings.service_event_id')
            ->leftJoin('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
            ->whereBetween('service_events.event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNull('service_events.deleted_at')
            ->selectRaw("COALESCE(packages.name, 'Custom Menu') as name")
            ->selectRaw('COUNT(DISTINCT service_events.service_event_id) as orders')
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) as revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'orders' => (int) $item->orders,
                'revenue' => round((float) $item->revenue, 2),
                'total_sales' => round((float) $item->revenue, 2),
            ]);

        $byCategory = DB::table('booking_items')
            ->leftJoin('menu_items', 'booking_items.menu_item_id', '=', 'menu_items.menu_item_id')
            ->leftJoin('meal_categories', 'menu_items.category_id', '=', 'meal_categories.category_id')
            ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.booking_id')
            ->whereBetween('booking_items.created_at', [$startDate, $endDate])
            ->whereNull('bookings.deleted_at')
            ->selectRaw("COALESCE(meal_categories.name, booking_items.item_type, 'Other') as name")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(booking_items.quantity * booking_items.unit_price), 0) as revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'orders' => (int) $item->orders,
                'revenue' => round((float) $item->revenue, 2),
                'value' => round((float) $item->revenue, 2),
            ]);

        $currentRevenue = (float) ($paymentSummary->total_collected ?? 0);
        $previousStart = $startDate->copy()->subDays($startDate->diffInDays($endDate) + 1);
        $previousEnd = $startDate->copy()->subSecond();
        $previousRevenue = $this->netCompletedPayments($previousStart, $previousEnd);

        return $this->ok([
            'summary' => [
                'total_sales' => round((float) ($invoiceTotals->total_sales ?? 0), 2),
                'total_collected' => round($currentRevenue, 2),
                'total_outstanding' => round((float) ($invoiceTotals->total_outstanding ?? 0), 2),
                'invoice_count' => (int) ($invoiceTotals->invoice_count ?? 0),
                'payment_count' => (int) ($paymentSummary->payment_count ?? 0),
                'average_invoice' => ($invoiceTotals->invoice_count ?? 0) > 0
                    ? round(((float) $invoiceTotals->total_sales) / max(1, (int) $invoiceTotals->invoice_count), 2)
                    : 0,
                'period' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
            ],
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'top_packages' => $topPackages,
            'by_category' => $byCategory,
            'revenue' => round($currentRevenue, 2),
            'total_revenue' => round($currentRevenue, 2),
            'revenue_growth' => $this->percentageChange($currentRevenue, $previousRevenue),
        ]);
    }

    public function inventory()
    {
        $movementTotals = InventoryMovement::selectRaw("DATE(created_at) as period")
            ->selectRaw("SUM(CASE WHEN movement_type = 'purchase' THEN ABS(quantity_change) ELSE 0 END) as incoming")
            ->selectRaw("SUM(CASE WHEN movement_type IN ('usage', 'return', 'adjustment') AND quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as outgoing")
            ->selectRaw("SUM(CASE WHEN movement_type = 'waste' THEN ABS(quantity_change) ELSE 0 END) as wastage")
            ->groupBy('period')
            ->orderBy('period')
            ->limit(60)
            ->get()
            ->map(fn($item) => [
                'period' => Carbon::parse($item->period)->format('M d'),
                'incoming' => round((float) $item->incoming, 2),
                'outgoing' => round((float) $item->outgoing, 2),
                'wastage' => round((float) $item->wastage, 2),
            ]);

        $usage = InventoryStock::with('ingredient')
            ->get()
            ->map(function ($stock) {
                $used = InventoryMovement::where('ingredient_id', $stock->ingredient_id)
                    ->whereIn('movement_type', ['usage', 'waste'])
                    ->sum(DB::raw('ABS(quantity_change)'));
                $min = (float) ($stock->minimum_quantity ?: $stock->reorder_point ?: 1);
                $current = (float) $stock->current_quantity;
                $percent = $min > 0 ? min(100, round(($current / max($min, 1)) * 100, 2)) : 100;

                return [
                    'ingredient_id' => $stock->ingredient_id,
                    'name' => $stock->ingredient?->name ?? 'Unknown Ingredient',
                    'unit' => $stock->ingredient?->unit ?? '',
                    'used_quantity' => round((float) $used, 2),
                    'current_quantity' => round($current, 2),
                    'minimum_quantity' => round($min, 2),
                    'reorder_point' => round((float) $stock->reorder_point, 2),
                    'stock_percent' => $percent,
                    'status' => $stock->stock_status,
                ];
            })
            ->sortBy('current_quantity')
            ->values();

        $menuPerformance = DB::table('menu_items')
            ->leftJoin('booking_items', 'menu_items.menu_item_id', '=', 'booking_items.menu_item_id')
            ->whereNull('menu_items.deleted_at')
            ->select('menu_items.menu_item_id', 'menu_items.name')
            ->selectRaw('COALESCE(SUM(booking_items.quantity), 0) as popularity')
            ->selectRaw('COALESCE(SUM(booking_items.quantity * booking_items.unit_price), 0) as revenue')
            ->selectRaw('COALESCE(SUM(booking_items.quantity * menu_items.cost_to_make), 0) as cost')
            ->groupBy('menu_items.menu_item_id', 'menu_items.name')
            ->orderByDesc('revenue')
            ->limit(12)
            ->get()
            ->map(function ($item) {
                $profit = (float) $item->revenue - (float) $item->cost;
                $margin = (float) $item->revenue > 0 ? ($profit / (float) $item->revenue) * 100 : 0;
                return [
                    'menu_item_id' => $item->menu_item_id,
                    'name' => $item->name,
                    'popularity' => (int) $item->popularity,
                    'revenue' => round((float) $item->revenue, 2),
                    'cost' => round((float) $item->cost, 2),
                    'profitability' => round($margin, 2),
                ];
            });

        $ingredientUsage = InventoryMovement::with('ingredient')
            ->whereIn('movement_type', ['usage', 'waste'])
            ->selectRaw('ingredient_id, SUM(ABS(quantity_change)) as used_quantity')
            ->groupBy('ingredient_id')
            ->orderByDesc('used_quantity')
            ->limit(12)
            ->get()
            ->map(fn($item) => [
                'ingredient_id' => $item->ingredient_id,
                'name' => $item->ingredient?->name ?? 'Unknown Ingredient',
                'used_quantity' => round((float) $item->used_quantity, 2),
                'unit' => $item->ingredient?->unit ?? '',
            ]);

        $stocks = InventoryStock::with('ingredient')->get();
        $totalStocks = max(1, $stocks->count());
        $healthyStocks = $stocks->filter(fn($stock) => ! in_array($stock->stock_status, ['out_of_stock', 'low_stock'], true))->count();

        return $this->ok([
            'usage' => $usage,
            'movements' => $movementTotals,
            'menu_performance' => $menuPerformance,
            'ingredient_usage' => $ingredientUsage,
            'stock_health' => round(($healthyStocks / $totalStocks) * 100, 2),
            'stocks' => $stocks,
            'low_stock' => $stocks->filter(fn($stock) => in_array($stock->stock_status, ['out_of_stock', 'low_stock'], true))->values(),
            'summary' => [
                'total_items' => $stocks->count(),
                'low_stock_count' => $stocks->filter(fn($stock) => $stock->stock_status === 'low_stock')->count(),
                'out_of_stock_count' => $stocks->filter(fn($stock) => $stock->stock_status === 'out_of_stock')->count(),
                'inventory_value' => round($stocks->sum(fn($stock) => ((float) $stock->current_quantity) * ((float) ($stock->ingredient?->unit_cost ?? 0))), 2),
            ],
        ]);
    }

    public function payroll(Request $request)
    {
        [$startDate, $endDate] = $this->dateRange($request);

        $items = PayrollItem::query()
            ->join('payrolls', 'payroll_items.payroll_id', '=', 'payrolls.payroll_id')
            ->whereBetween('payrolls.created_at', [$startDate, $endDate]);

        $gross = (clone $items)->where('payroll_items.item_type', 'earning')->sum('payroll_items.amount');
        $deductions = (clone $items)->where('payroll_items.item_type', 'deduction')->sum('payroll_items.amount');

        $summary = DB::table('payrolls')
            ->join('employees', 'payrolls.employee_id', '=', 'employees.employee_id')
            ->join('persons', 'employees.person_id', '=', 'persons.person_id')
            ->leftJoin('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereBetween('payrolls.created_at', [$startDate, $endDate])
            ->selectRaw("employees.employee_id")
            ->selectRaw("CONCAT(persons.first_name, ' ', persons.last_name) as employee_name")
            ->selectRaw("SUM(CASE WHEN payroll_items.item_type = 'earning' THEN payroll_items.amount ELSE 0 END) as gross_pay")
            ->selectRaw("SUM(CASE WHEN payroll_items.item_type = 'deduction' THEN payroll_items.amount ELSE 0 END) as deductions")
            ->selectRaw("SUM(CASE WHEN payroll_items.item_type = 'earning' THEN payroll_items.amount ELSE -payroll_items.amount END) as net_pay")
            ->groupBy('employees.employee_id', 'persons.first_name', 'persons.last_name')
            ->orderByDesc('net_pay')
            ->get()
            ->map(fn($item) => [
                'employee_id' => $item->employee_id,
                'employee_name' => $item->employee_name,
                'gross_pay' => round((float) $item->gross_pay, 2),
                'deductions' => round((float) $item->deductions, 2),
                'net_pay' => round((float) $item->net_pay, 2),
            ]);

        $byDepartment = DB::table('departments')
            ->join('employees', 'departments.department_id', '=', 'employees.department_id')
            ->leftJoin('payrolls', 'employees.employee_id', '=', 'payrolls.employee_id')
            ->leftJoin('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereNull('payrolls.payroll_id')
                    ->orWhereBetween('payrolls.created_at', [$startDate, $endDate]);
            })
            ->select('departments.name')
            ->selectRaw("COALESCE(SUM(CASE WHEN payroll_items.item_type = 'earning' THEN payroll_items.amount ELSE -payroll_items.amount END), 0) as total")
            ->groupBy('departments.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => ['name' => $item->name, 'value' => round((float) $item->total, 2), 'total' => round((float) $item->total, 2)]);

        return $this->ok([
            'summary' => $summary,
            'by_department' => $byDepartment,
            'active_staff' => Employee::where('status', 'active')->count(),
            'gross' => round((float) $gross, 2),
            'deductions' => round((float) $deductions, 2),
            'net' => round((float) $gross - (float) $deductions, 2),
            'total_payroll' => round((float) $gross - (float) $deductions, 2),
        ]);
    }

    public function events(Request $request)
    {
        [$startDate, $endDate] = $this->dateRange($request);

        $periodBookingIds = ServiceEvent::whereBetween('event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('service_event_id');

        $totalBookings = Booking::whereIn('service_event_id', $periodBookingIds)->count();
        $completed = Booking::whereIn('service_event_id', $periodBookingIds)
            ->where('booking_status', 'completed')->count();
        $cancelled = Booking::whereIn('service_event_id', $periodBookingIds)
            ->where('booking_status', 'cancelled')->count();
        $active = Booking::whereIn('service_event_id', $periodBookingIds)
            ->whereIn('booking_status', ['pending_approval', 'confirmed', 'rescheduled', 'reschedule_requested'])->count();

        $trends = ServiceEvent::whereBetween('event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("DATE_FORMAT(event_date, '%Y-%m') as period")
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn($item) => [
                'period' => Carbon::parse($item->period . '-01')->format('M'),
                'events' => (int) $item->events,
                'bookings' => (int) $item->events,
                'completed' => (int) $item->completed,
                'cancelled' => (int) $item->cancelled,
            ]);

        $eventTypes = ServiceEvent::leftJoin('event_types', 'service_events.event_type_id', '=', 'event_types.event_type_id')
            ->selectRaw("COALESCE(event_types.name, 'Unknown') as name")
            ->selectRaw('COUNT(*) as value')
            ->groupBy('name')
            ->orderByDesc('value')
            ->get();

        $statusSummary = ServiceEvent::selectRaw('status, COUNT(*) total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $profitability = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'invoice', 'items.menuItem'])
            ->latest('booking_id')
            ->limit(25)
            ->get()
            ->map(function ($booking) {
                $revenue = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
                $directCost = $booking->items->sum(fn($item) => ((float) $item->quantity) * ((float) ($item->menuItem?->cost_to_make ?? 0)));
                $profit = $revenue - $directCost;
                $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                return [
                    'event_id' => $booking->service_event_id,
                    'event_name' => $booking->serviceEvent?->eventType?->name ?? $booking->booking_no,
                    'customer_name' => $booking->serviceEvent?->customer?->person
                        ? trim($booking->serviceEvent->customer->person->first_name . ' ' . $booking->serviceEvent->customer->person->last_name)
                        : 'Unknown Customer',
                    'revenue' => round($revenue, 2),
                    'cost' => round($directCost, 2),
                    'profit' => round($profit, 2),
                    'margin' => round($margin, 2),
                    'status' => $booking->booking_status,
                ];
            });

        $peak = ServiceEvent::selectRaw("DATE_FORMAT(event_date, '%M') as month_name")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('month_name')
            ->orderByDesc('total')
            ->first();

        return $this->ok([
            'events' => ServiceEvent::with(['customer.person', 'eventType', 'booking'])
                ->whereBetween('event_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->latest()
                ->get(),
            'by_status' => $statusSummary,
            'trends' => $trends,
            'event_types' => $eventTypes,
            'status_summary' => $statusSummary,
            'profitability' => $profitability,
            'total_bookings' => $totalBookings,
            'conversion_rate' => $totalBookings > 0 ? round((Booking::whereIn('booking_status', ['confirmed', 'completed'])->count() / $totalBookings) * 100, 2) : 0,
            'cancellation_rate' => $totalBookings > 0 ? round(($cancelled / $totalBookings) * 100, 2) : 0,
            'peak_season' => $peak?->month_name ?? 'N/A',
            'active_bookings' => $active,
            'completed_events' => $completed,
            'ongoing_events' => ServiceEvent::where('status', 'ongoing')->count(),
            'cancelled_events' => $cancelled,
            'completion_rate' => $totalBookings > 0 ? round(($completed / $totalBookings) * 100, 2) : 0,
            'conversion_growth' => 0,
        ]);
    }

    public function customers()
    {
        $customers = Customer::with('person')->get();

        return $this->ok([
            'customers' => $customers,
            'total' => $customers->count(),
            'active' => $customers->where('is_active', true)->count(),
            'new_this_month' => Customer::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
        ]);
    }

    public function financial(Request $request)
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $expensesByMonth = $this->expensesByMonth($year);
        $invoiceByMonth = Invoice::query()
            ->where('status', '!=', 'cancelled')
            ->whereHas('booking.serviceEvent', function ($q) use ($year) {
                $q->whereYear('event_date', $year);
            })
            ->join('bookings', 'invoices.booking_id', '=', 'bookings.booking_id')
            ->join('service_events', 'bookings.service_event_id', '=', 'service_events.service_event_id')
            ->selectRaw('MONTH(service_events.event_date) as month')
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) as revenue')
            ->selectRaw('COALESCE(SUM(invoices.paid_amount), 0) as collected')
            ->selectRaw('COALESCE(SUM(GREATEST(invoices.total_amount - invoices.paid_amount, 0)), 0) as outstanding')
            ->selectRaw('COUNT(*) as invoice_count')
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $monthly = collect(range(1, 12))->map(function ($month) use ($invoiceByMonth, $expensesByMonth) {
            $row = $invoiceByMonth->get($month);
            $revenue = (float) ($row->revenue ?? 0);
            $collected = (float) ($row->collected ?? 0);
            $outstanding = (float) ($row->outstanding ?? 0);
            $expenses = (float) ($expensesByMonth[$month] ?? 0);
            $profit = $revenue - $expenses;

            return [
                'month' => Carbon::create(null, $month, 1)->format('M'),
                'month_number' => $month,
                'revenue' => round($revenue, 2),
                'collected' => round($collected, 2),
                'outstanding' => round($outstanding, 2),
                'expenses' => round($expenses, 2),
                'profit' => round($profit, 2),
                'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                'invoice_count' => (int) ($row->invoice_count ?? 0),
            ];
        })->values();

        $totalRevenue = round($monthly->sum('revenue'), 2);
        $totalCollected = round($monthly->sum('collected'), 2);
        $totalExpenses = round($monthly->sum('expenses'), 2);
        $totalProfit = round($totalRevenue - $totalExpenses, 2);
        $profitMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0;

        $outstanding = Invoice::with('booking.serviceEvent.customer.person')
            ->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->whereRaw('paid_amount < total_amount')
            ->latest('due_date')
            ->limit(25)
            ->get()
            ->map(fn($invoice) => [
                'invoice_id' => $invoice->invoice_id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->booking?->serviceEvent?->customer?->person
                    ? trim($invoice->booking->serviceEvent->customer->person->first_name . ' ' . $invoice->booking->serviceEvent->customer->person->last_name)
                    : 'Unknown Customer',
                'total_amount' => round((float) $invoice->total_amount, 2),
                'paid_amount' => round((float) $invoice->paid_amount, 2),
                'balance' => round(max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount), 2),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status_badge,
            ]);

        $currentMonthProfit = (float) ($monthly->firstWhere('month_number', now()->month)['profit'] ?? 0);
        $previousMonthProfit = (float) ($monthly->firstWhere('month_number', now()->subMonthNoOverflow()->month)['profit'] ?? 0);
        $currentMonthRevenue = (float) ($monthly->firstWhere('month_number', now()->month)['revenue'] ?? 0);
        $previousMonthRevenue = (float) ($monthly->firstWhere('month_number', now()->subMonthNoOverflow()->month)['revenue'] ?? 0);

        return $this->ok([
            'monthly' => $monthly,
            'outstanding' => $outstanding,
            'revenue_vs_expenses' => $monthly->map(fn($row) => [
                'month' => $row['month'],
                'revenue' => $row['revenue'],
                'expenses' => $row['expenses'],
                'profit' => $row['profit'],
            ]),
            'profit_margins' => $monthly->map(fn($row) => [
                'month' => $row['month'],
                'profit_margin' => $row['profit_margin'],
                'margin' => $row['profit_margin'],
            ]),
            'total_revenue' => $totalRevenue,
            'total_collected' => $totalCollected,
            'total_expenses' => $totalExpenses,
            'total_profit' => $totalProfit,
            'revenue_growth' => $this->percentageChange($currentMonthRevenue, $previousMonthRevenue),
            'profit_margin' => $profitMargin,
            'margin_growth' => $this->percentageChange($currentMonthProfit, $previousMonthProfit),
            'avg_rating' => round((float) Review::where('is_approved', true)->avg('overall_rating'), 2),
            'rating_growth' => 0,
            'summary' => [
                'collection_rate' => $totalRevenue > 0 ? round(($totalCollected / $totalRevenue) * 100, 2) : 0,
                'outstanding_balance' => round($outstanding->sum('balance'), 2),
                'invoice_count' => Invoice::whereYear('created_at', $year)->where('status', '!=', 'cancelled')->count(),
            ],
        ]);
    }

    public function additional(Request $request)
    {
        [$startDate, $endDate] = $this->dateRange($request);

        $eventCompletion = ServiceEvent::whereBetween('event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("DATE_FORMAT(event_date, '%Y-%m') as period")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn($item) => [
                'period' => Carbon::parse($item->period . '-01')->format('M'),
                'completion_rate' => $item->total > 0 ? round(((float) $item->completed / (float) $item->total) * 100, 2) : 0,
            ]);

        $revenueByEventType = ServiceEvent::leftJoin('event_types', 'service_events.event_type_id', '=', 'event_types.event_type_id')
            ->leftJoin('bookings', 'service_events.service_event_id', '=', 'bookings.service_event_id')
            ->leftJoin('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
            ->whereBetween('service_events.event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("COALESCE(event_types.name, 'Unknown') as name")
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) as revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($item) => ['name' => $item->name, 'revenue' => round((float) $item->revenue, 2), 'value' => round((float) $item->revenue, 2)]);

        return $this->ok([
            'event_completion' => $eventCompletion,
            'revenue_by_event_type' => $revenueByEventType,
        ]);
    }

    private function invoiceSeries(Carbon $startDate, Carbon $endDate, string $groupExpression, string $periodKey)
    {
        return Invoice::query()
            ->join('bookings', 'invoices.booking_id', '=', 'bookings.booking_id')
            ->join('service_events', 'bookings.service_event_id', '=', 'service_events.service_event_id')
            ->where('invoices.status', '!=', 'cancelled')
            ->whereBetween('service_events.event_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw(str_replace('created_at', 'service_events.event_date', $groupExpression) . ' as period')
            ->selectRaw('COUNT(DISTINCT invoices.invoice_id) as orders')
            ->selectRaw('COALESCE(SUM(invoices.total_amount), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(invoices.paid_amount), 0) as revenue')
            ->selectRaw('COALESCE(SUM(GREATEST(invoices.total_amount - invoices.paid_amount, 0)), 0) as outstanding')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(function ($item) use ($periodKey) {
                return [
                    $periodKey => $item->period,
                    'period' => $item->period,
                    'date' => $item->period,
                    'orders' => (int) $item->orders,
                    'total_sales' => round((float) $item->total_sales, 2),
                    'revenue' => round((float) $item->revenue, 2),
                    'outstanding' => round((float) $item->outstanding, 2),
                ];
            });
    }

    private function dateRange(Request $request): array
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        try {
            $startDate = $start && $start !== 'N/A' ? Carbon::parse($start)->startOfDay() : now()->startOfMonth();
            $endDate = $end && $end !== 'N/A' ? Carbon::parse($end)->endOfDay() : now()->endOfMonth();
        } catch (\Throwable) {
            $startDate = now()->startOfMonth();
            $endDate = now()->endOfMonth();
        }

        return [$startDate, $endDate];
    }

    private function expensesByMonth(int $year): array
    {
        $inventory = InventoryMovement::whereYear('created_at', $year)
            ->whereIn('movement_type', ['purchase', 'waste'])
            ->selectRaw('MONTH(created_at) as month')
            ->selectRaw('COALESCE(SUM(ABS(quantity_change) * unit_cost_at_time), 0) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month')
            ->toArray();

        $payroll = DB::table('payrolls')
            ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereYear('payrolls.created_at', $year)
            ->where('payroll_items.item_type', 'earning')
            ->selectRaw('MONTH(payrolls.created_at) as month')
            ->selectRaw('COALESCE(SUM(payroll_items.amount), 0) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month')
            ->toArray();

        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $result[$month] = round((float) ($inventory[$month] ?? 0) + (float) ($payroll[$month] ?? 0), 2);
        }

        return $result;
    }

    private function netCompletedPayments(Carbon $startDate, Carbon $endDate): float
    {
        return (float) BookingPayment::where('status', 'completed')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -ABS(amount) ELSE amount END), 0) as total")
            ->value('total');
    }

    private function percentageChange(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }
}
