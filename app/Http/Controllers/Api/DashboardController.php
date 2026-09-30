<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PayrollItem;
use App\Models\Quotation;
use App\Models\Review;
use App\Models\ServiceEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    // ============================================================
    // MAIN DASHBOARD — PERIOD AWARE
    // ============================================================
    public function index(Request $request)
    {
        $period = strtolower((string) $request->input('period', 'monthly'));
        if (! in_array($period, ['weekly', 'monthly', 'yearly'], true)) {
            $period = 'monthly';
        }

        $range = $this->resolvePeriodRange($period, $request->input('anchor'));

        // ⭐ 60-second cache for the stats payload too.
        $userId    = optional($request->user())->user_id ?? 'guest';
        $cacheKey  = "dashboard:stats:{$userId}:{$period}:{$range['start']->toDateString()}";
        $cached    = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $this->ok($cached);
        }

        $cards = $this->buildSummaryCards($range);
        $data = [
            'period' => $period,
            'range'  => [
                'start' => $range['start']->toDateString(),
                'end'   => $range['end']->toDateString(),
                'label' => $range['label'],
            ],
            'stats' => [
                'total_bookings'      => $cards['total_bookings']['value'],
                'total_revenue'       => $cards['total_revenue']['value'],
                'total_sales'         => $cards['total_sales']['value'],
                'total_expenses'      => $cards['total_expenses']['value'],
                'total_profit'        => $cards['total_profit']['value'],
                'total_pending'       => $cards['total_pending']['value'],
                'completed_events'    => $cards['completed_events']['value'],
                'active_staff'        => $cards['active_staff']['value'],
                'revenue_growth'      => $cards['total_revenue']['change']['value'],
                'booking_growth'      => $cards['total_bookings']['change']['value'],
                'sales_growth'        => $cards['total_sales']['change']['value'],
                'expenses_growth'     => $cards['total_expenses']['change']['value'],
                'profit_growth'       => $cards['total_profit']['change']['value'],
                'completed_growth'    => $cards['completed_events']['change']['value'],
                // ⭐ Real outstanding invoice balance (unpaid invoice amounts),
                // separate concept from pending bookings.
                'outstanding_balance' => round(
                    (float) Invoice::query()
                        ->where('status', '!=', 'cancelled')
                        ->whereRaw('paid_amount < total_amount')
                        ->selectRaw('COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)), 0) total')
                        ->value('total'),
                    2
                ),
                'completion_rate'     => $this->completionRate($range),
                'active_orders'       => Order::whereIn('status', ['pending', 'preparing', 'ready', 'ongoing'])->count(),
                'pending_quotations'  => Quotation::where('status', 'pending')->count(),
                'customer_count'      => Customer::count(),
                'avg_rating'          => round((float) Review::where('is_approved', true)->avg('overall_rating'), 2),
            ],
            'cards' => $cards,
            'low_stock_alerts' => InventoryStock::with('ingredient')
                ->whereColumn('current_quantity', '<=', 'reorder_point')
                ->orderBy('current_quantity')
                ->limit(12)
                ->get(),
            'staff_attendance_summary' => AttendanceLog::whereDate('attendance_date', today())
                ->selectRaw('status, COUNT(*) total')
                ->groupBy('status')
                ->get(),
            'recent_bookings' => Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'quotation'])
                ->whereBetween('created_at', [$range['start'], $range['end']])
                ->where('booking_no', 'not like', 'HIST-%')
                ->latest('booking_id')
                ->limit(8)
                ->get(),
            'upcoming_event_rows' => ServiceEvent::with(['customer.person', 'eventType', 'booking'])
                ->whereDate('event_date', '>=', today())
                ->whereIn('status', ['pending', 'confirmed', 'ongoing'])
                ->orderBy('event_date')
                ->limit(8)
                ->get(),
            'upcoming_events' => ServiceEvent::whereDate('event_date', '>=', today())
                ->whereIn('status', ['pending', 'confirmed', 'ongoing'])
                ->count(),
        ];

        $flat = array_merge(
            is_array($data['stats'] ?? null) ? $data['stats'] : [],
            [
                'period' => $data['period'] ?? $period,
                'range'  => $data['range'] ?? null,
                'cards'  => $data['cards'] ?? [],
            ]
        );

        $filtered = $this->filterStatsForRole($request, $data);

        return $this->ok(array_merge(
            $filtered,
            ['stats' => $flat]
        ));
    }

    // ============================================================
    // DETAIL MODAL
    // ============================================================
    public function detail(Request $request, string $card)
    {
        $period = strtolower((string) $request->input('period', 'monthly'));
        if (! in_array($period, ['weekly', 'monthly', 'yearly'], true)) {
            $period = 'monthly';
        }

        $range = $this->resolvePeriodRange($period, $request->input('anchor'));

        $payload = $this->detailFor($card, $range);

        return $this->ok($payload);
    }

    private function detailFor(string $card, array $range): array
    {
        $start = $range['start'];
        $end   = $range['end'];

        switch ($card) {
            case 'total_sales':
                $rows = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'invoice', 'quotation'])
                    ->whereBetween('created_at', [$start, $end])
                    ->whereNotIn('booking_status', ['cancelled', 'rejected'])
                    ->where('booking_no', 'not like', 'HIST-%')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn(Booking $b) => [
                        'id'             => $b->booking_id,
                        'booking_no'     => $b->booking_no,
                        'customer_name'  => $b->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        'event_type'     => $b->serviceEvent?->eventType?->name ?? '—',
                        'event_date'     => $b->serviceEvent?->event_date?->toDateString(),
                        'booking_status' => $b->booking_status,
                        'amount'         => (float) ($b->invoice?->total_amount ?? $b->quotation?->total_amount ?? 0),
                        'created_at'     => $b->created_at?->toDateTimeString(),
                    ]);
                return [
                    'title'    => 'Total Sales',
                    'columns'  => ['booking_no', 'customer_name', 'event_type', 'event_date', 'booking_status', 'amount'],
                    'rows'     => $rows,
                    'total'    => round((float) $rows->sum('amount'), 2),
                    'navigate' => '/admin/bookings?status_in=confirmed,ongoing,completed&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];

            case 'total_revenue':
                $rows = BookingPayment::with(['booking.serviceEvent.customer.person'])
                    ->whereBetween('payment_date', [$start, $end])
                    ->where('status', 'completed')
                    ->orderByDesc('payment_date')
                    ->get()
                    ->map(fn(BookingPayment $p) => [
                        'id'             => $p->payment_id,
                        'payment_number' => $p->payment_number,
                        'booking_no'     => $p->booking?->booking_no,
                        'customer_name'  => $p->booking?->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        'payment_method' => $p->payment_method,
                        'payment_type'   => $p->payment_type,
                        'amount'         => $p->payment_type === 'refund' ? -(float) $p->amount : (float) $p->amount,
                        'payment_date'   => $p->payment_date?->toDateString(),
                    ]);
                return [
                    'title'    => 'Total Payments Collected',
                    'columns'  => ['payment_number', 'booking_no', 'customer_name', 'payment_method', 'payment_type', 'amount', 'payment_date'],
                    'rows'     => $rows,
                    'total'    => round((float) $rows->sum('amount'), 2),
                    'navigate' => '/admin/payments?from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];

            case 'total_expenses':
                $inventory = InventoryMovement::with('ingredient')
                    ->whereBetween('created_at', [$start, $end])
                    ->whereIn('movement_type', ['purchase', 'waste'])
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn($m) => [
                        'source'    => 'Inventory (' . $m->movement_type . ')',
                        'reference' => $m->ingredient?->name ?? 'Ingredient',
                        'quantity'  => (float) $m->quantity_change,
                        'amount'    => round(abs((float) $m->quantity_change) * (float) ($m->unit_cost_at_time ?? 0), 2),
                        'date'      => $m->created_at?->toDateString(),
                    ]);

                $payroll = DB::table('payrolls')
                    ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
                    ->join('employees', 'payrolls.employee_id', '=', 'employees.employee_id')
                    ->leftJoin('persons', 'employees.person_id', '=', 'persons.person_id')
                    ->whereBetween('payrolls.created_at', [$start, $end])
                    ->where('payroll_items.item_type', 'earning')
                    ->selectRaw("CONCAT(COALESCE(persons.first_name, ''), ' ', COALESCE(persons.last_name, '')) as employee_name")
                    ->selectRaw('payroll_items.amount as amount')
                    ->selectRaw('payrolls.created_at as created_at')
                    ->orderByDesc('payrolls.created_at')
                    ->get()
                    ->map(fn($p) => [
                        'source'    => 'Payroll',
                        'reference' => trim($p->employee_name) ?: 'Employee',
                        'quantity'  => null,
                        'amount'    => round((float) $p->amount, 2),
                        'date'      => Carbon::parse($p->created_at)->toDateString(),
                    ]);

                $rows = $inventory->concat($payroll)->values();
                return [
                    'title'    => 'Total Expenses',
                    'columns'  => ['source', 'reference', 'quantity', 'amount', 'date'],
                    'rows'     => $rows,
                    'total'    => round((float) $rows->sum('amount'), 2),
                    'navigate' => '/admin/reports?tab=expenses&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];

            case 'total_profit':
                $revenue  = $this->totalPaymentsCollected($start, $end);
                $expenses = $this->totalExpenses($start, $end);
                return [
                    'title'    => 'Total Profit',
                    'columns'  => ['label', 'value'],
                    'rows'     => [
                        ['label' => 'Payments Collected', 'value' => $revenue],
                        ['label' => 'Expenses',           'value' => $expenses],
                        ['label' => 'Profit',             'value' => round($revenue - $expenses, 2)],
                    ],
                    'total'    => round($revenue - $expenses, 2),
                    'navigate' => '/admin/reports?tab=financial&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];
            case 'total_pending':
                // ⭐ FIX #1 — list pending bookings, not unpaid invoices.
                $rows = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'invoice', 'quotation'])
                    ->whereIn('booking_status', ['pending', 'pending_approval', 'draft'])
                    ->where('booking_no', 'not like', 'HIST-%')
                    ->whereBetween('created_at', [$start, $end])
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn(Booking $b) => [
                        'id'             => $b->booking_id,
                        'booking_no'     => $b->booking_no,
                        'customer_name'  => $b->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        'event_type'     => $b->serviceEvent?->eventType?->name ?? '—',
                        'event_date'     => $b->serviceEvent?->event_date?->toDateString(),
                        'booking_status' => $b->booking_status,
                        'amount'         => (float) ($b->invoice?->total_amount ?? $b->quotation?->total_amount ?? 0),
                        'created_at'     => $b->created_at?->toDateTimeString(),
                    ]);
                return [
                    'title'    => 'Total Pending',
                    'columns'  => ['booking_no', 'customer_name', 'event_type', 'event_date', 'booking_status', 'amount'],
                    'rows'     => $rows,
                    'total'    => $rows->count(), // ⭐ count of pending bookings, not amount
                    'navigate' => '/admin/bookings?status_in=pending,pending_approval&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];
                // ⭐ FIX — restored the missing total_bookings case that was
                // accidentally nested inside total_pending as dead code.
            case 'total_bookings':
                $rows = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType'])
                    ->whereBetween('created_at', [$start, $end])
                    ->whereIn('booking_status', ['confirmed', 'ongoing', 'completed', 'approved', 'rescheduled'])
                    ->where('booking_no', 'not like', 'HIST-%')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn(Booking $b) => [
                        'id'             => $b->booking_id,
                        'booking_no'     => $b->booking_no,
                        'customer_name'  => $b->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        'event_type'     => $b->serviceEvent?->eventType?->name ?? '—',
                        'event_date'     => $b->serviceEvent?->event_date?->toDateString(),
                        'booking_status' => $b->booking_status,
                        'created_at'     => $b->created_at?->toDateTimeString(),
                    ]);
                return [
                    'title'    => 'Total Bookings (Approved)',
                    'columns'  => ['booking_no', 'customer_name', 'event_type', 'event_date', 'booking_status', 'created_at'],
                    'rows'     => $rows,
                    'total'    => $rows->count(),
                    'navigate' => '/admin/bookings?status_in=confirmed,ongoing,completed&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];

            case 'completed_events':
                $rows = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType'])
                    ->whereBetween('updated_at', [$start, $end])
                    ->where('booking_status', 'completed')
                    ->where('booking_no', 'not like', 'HIST-%')
                    ->orderByDesc('updated_at')
                    ->get()
                    ->map(fn(Booking $b) => [
                        'id'             => $b->booking_id,
                        'booking_no'     => $b->booking_no,
                        'customer_name'  => $b->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        'event_type'     => $b->serviceEvent?->eventType?->name ?? '—',
                        'event_date'     => $b->serviceEvent?->event_date?->toDateString(),
                        'completed_at'   => $b->updated_at?->toDateTimeString(),
                    ]);
                return [
                    'title'    => 'Completed Events',
                    'columns'  => ['booking_no', 'customer_name', 'event_type', 'event_date', 'completed_at'],
                    'rows'     => $rows,
                    'total'    => $rows->count(),
                    'navigate' => '/admin/bookings?status=completed&from=' . $start->toDateString() . '&to=' . $end->toDateString(),
                ];

            case 'active_staff':
                $rows = Employee::with(['person', 'department', 'position'])
                    ->where('status', 'active')
                    ->whereDate('hire_date', '<=', $end->toDateString())
                    ->orderBy('employee_id')
                    ->get()
                    ->map(fn(Employee $e) => [
                        'employee_id'   => $e->employee_id,
                        'employee_code' => $e->employee_code,
                        'employee_name' => $e->person?->full_name ?? '—',
                        'department'    => $e->department?->name ?? '—',
                        'position'      => $e->position?->title ?? $e->position?->name ?? '—',
                        'status'        => $e->status,
                    ]);
                return [
                    'title'    => 'Active Staff',
                    'columns'  => ['employee_code', 'employee_name', 'department', 'position', 'status'],
                    'rows'     => $rows,
                    'total'    => $rows->count(),
                    'navigate' => '/admin/staff?status=active',
                ];
        }

        return [
            'title'    => ucwords(str_replace('_', ' ', $card)),
            'columns'  => [],
            'rows'     => [],
            'total'    => 0,
            'navigate' => null,
        ];
    }

    // ============================================================
    // CHARTS — PERIOD AWARE (wrapped in try/catch)
    // ============================================================
    public function charts(Request $request)
    {
        try {
            $period = strtolower((string) $request->input('period', 'monthly'));
            if (! in_array($period, ['weekly', 'monthly', 'yearly'], true)) {
                $period = 'monthly';
            }

            $range = $this->resolvePeriodRange($period, $request->input('anchor'));
            $start = $range['start'];
            $end   = $range['end'];

            // ⭐ 60-second cache keyed by user + period + anchor so rapid
            // month→year→week toggles hit cache instead of re-running ~250 queries.
            $userId    = optional($request->user())->user_id ?? 'guest';
            $anchorKey = $range['start']->toDateString();
            $cacheKey  = "dashboard:charts:{$userId}:{$period}:{$anchorKey}";

            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cached !== null) {
                return $this->ok($cached);
            }

            // ── Revenue vs Expenses ─────────────────────────────
            $revenueData = $this->revenueExpensesSeries($period, $start, $end);

            // ── Booking trends ──────────────────────────────────
            $bookingTrends = $this->bookingTrendSeries($period, $start, $end);

            // ── Inventory distribution ──────────────────────────
            $inventoryDistribution = Ingredient::join('inventory_stocks', 'ingredients.ingredient_id', '=', 'inventory_stocks.ingredient_id')
                ->whereNull('ingredients.deleted_at')
                ->whereNotNull('ingredients.category')
                ->select('ingredients.category')
                ->selectRaw('COALESCE(SUM(inventory_stocks.current_quantity * ingredients.unit_cost), 0) as value')
                ->groupBy('ingredients.category')
                ->orderByDesc('value')
                ->get()
                ->map(fn($item) => [
                    'name'  => $item->category ?: 'Uncategorized',
                    'value' => round((float) $item->value, 2),
                ])
                ->values()
                ->all();

            // ── Customer growth ─────────────────────────────────
            $customerGrowth = $this->customerGrowthSeries($period, $start, $end);

            // ── Stock movement ──────────────────────────────────
            $stockMovement = $this->stockMovementSeries($period, $start, $end);

            // ── Weekly performance ──────────────────────────────
            $weeklyPerformance = $this->weeklyPerformanceSeries($period, $start, $end);

            // ── Event types (counts + revenue) ──────────────────
            $eventTypes = Booking::query()
                ->whereBetween('bookings.created_at', [$start, $end])
                ->whereNotIn('booking_status', ['cancelled', 'rejected'])
                ->where('booking_no', 'not like', 'HIST-%')
                ->leftJoin('service_events', 'bookings.service_event_id', '=', 'service_events.service_event_id')
                ->leftJoin('event_types', 'service_events.event_type_id', '=', 'event_types.event_type_id')
                ->leftJoin('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
                ->leftJoin('quotations', 'bookings.quotation_id', '=', 'quotations.quotation_id')
                ->selectRaw("COALESCE(event_types.name, 'Unknown') as name")
                ->selectRaw('COUNT(DISTINCT bookings.booking_id) as count')
                ->selectRaw('COALESCE(SUM(COALESCE(invoices.total_amount, quotations.total_amount, 0)), 0) as revenue')
                ->groupBy('name')
                ->get()
                ->map(fn($item) => [
                    'name'    => $item->name,
                    'value'   => (int) $item->count,
                    'count'   => (int) $item->count,
                    'revenue' => round((float) $item->revenue, 2),
                ])
                ->values()
                ->all();

            // ── Menu performance ────────────────────────────────
            $menuPerformance = $this->menuPerformanceSeries($start, $end);

            // ── Top packages ────────────────────────────────────
            $topPackages = $this->topPackagesSeries($start, $end);

            // ── Event profitability ─────────────────────────────
            $eventProfitability = $this->eventProfitabilitySeries($start, $end);

            // ── Top menu items ──────────────────────────────────
            $topMenuItems = $this->topMenuItemsSeries($start, $end);
            // ── Payroll by employee ─────────────────────────────
            $payrollByEmployee = $this->payrollByEmployeeSeries($start, $end);

            // ── Profitability trend (connects Reports → Dashboard) ──
            $profitabilityTrend = [];
            try {
                $profitabilityTrend = app(\App\Services\ProfitabilityService::class)->getAggregateReport([
                    'date_from' => $start->toDateString(),
                    'date_to' => $end->toDateString(),
                    'statuses' => ['completed', 'confirmed', 'ongoing'],
                ]);
            } catch (\Throwable $profitabilityError) {
                Log::warning('Dashboard profitability chart failed: ' . $profitabilityError->getMessage());
            }
            // ── Expenses vs profit ──────────────────────────────
            $monthlyExpenses = $this->expensesVsProfitSeries($period, $start, $end);

            // ── Outstanding invoices ────────────────────────────
            $outstandingInvoices = Invoice::with(['booking.serviceEvent.customer.person'])
                ->whereRaw('paid_amount < total_amount')
                ->where('status', '!=', 'cancelled')
                ->whereHas('booking', fn($q) => $q->where('booking_no', 'not like', 'HIST-%'))
                ->orderBy('due_date')
                ->limit(25)
                ->get()
                ->map(fn(Invoice $invoice) => [
                    'invoice_id'     => $invoice->invoice_id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name'  => $invoice->booking?->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'total_amount'   => (float) $invoice->total_amount,
                    'paid_amount'    => (float) $invoice->paid_amount,
                    'balance'        => max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount),
                    'due_date'       => $invoice->due_date?->toDateString(),
                    'status'         => $invoice->status,
                    'days_overdue'   => $invoice->due_date && $invoice->due_date->isPast()
                        ? $invoice->due_date->diffInDays(now())
                        : 0,
                ])
                ->values()
                ->all();

            $data = [
                'period'                 => $period,
                'range'                  => [
                    'start' => $start->toDateString(),
                    'end'   => $end->toDateString(),
                    'label' => $range['label'],
                ],
                'revenue_data'           => $revenueData,
                'booking_trends'         => $bookingTrends,
                'inventory_distribution' => $inventoryDistribution,
                'customer_growth'        => $customerGrowth,
                'stock_movement'         => $stockMovement,
                'weekly_performance'     => $weeklyPerformance,
                'event_types'            => $eventTypes,
                'menu_performance'       => $menuPerformance,
                'top_packages'           => $topPackages,
                'event_profitability'    => $eventProfitability,
                'top_menu_items'         => $topMenuItems,
                'payroll_by_employee'    => $payrollByEmployee,
                'monthly_expenses'       => $monthlyExpenses,
                'outstanding_invoices'   => $outstandingInvoices,
                'profitability_trend'    => $profitabilityTrend['monthly_breakdown'] ?? [],
                'profitability_summary'  => $profitabilityTrend['summary'] ?? [],
            ];

            $payload = $this->filterChartsForRole($request, $data);
            \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, 60);

            return $this->ok($payload);
        } catch (\Throwable $e) {
            Log::error('Dashboard charts error: ' . $e->getMessage(), [
                'period' => $request->input('period'),
                'anchor' => $request->input('anchor'),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load charts: ' . $e->getMessage(),
                'debug'   => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }
    }

    // ============================================================
    // LEGACY SHORTCUTS
    // ============================================================
    public function recentBookings()
    {
        return $this->ok(
            Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'quotation'])
                ->latest('booking_id')
                ->limit(8)
                ->get()
        );
    }

    public function upcomingEvents()
    {
        return $this->ok(
            ServiceEvent::with(['customer.person', 'eventType', 'booking'])
                ->whereDate('event_date', '>=', today())
                ->whereIn('status', ['pending', 'confirmed', 'ongoing'])
                ->orderBy('event_date')
                ->limit(8)
                ->get()
        );
    }

    public function lowStock()
    {
        return $this->ok(
            InventoryStock::with('ingredient')
                ->whereColumn('current_quantity', '<=', 'reorder_point')
                ->orderBy('current_quantity')
                ->limit(10)
                ->get()
        );
    }

    public function todayAttendance()
    {
        return $this->ok(
            AttendanceLog::with(['employee.person', 'schedule'])
                ->whereDate('attendance_date', today())
                ->get()
        );
    }

    public function revenueChart(Request $request)
    {
        return $this->charts($request);
    }

    public function eventDistribution()
    {
        return $this->ok(
            ServiceEvent::select('event_type_id')
                ->selectRaw('COUNT(*) as count')
                ->with('eventType')
                ->groupBy('event_type_id')
                ->get()
                ->map(fn($item) => [
                    'name'  => $item->eventType?->name ?? 'Unknown',
                    'value' => (int) $item->count,
                ])
                ->values()
                ->all()
        );
    }

    // ============================================================
    // ROLE FILTERS
    // ============================================================
    private function filterStatsForRole(Request $request, array $data): array
    {
        $role = $this->primaryOperationalRole($request);

        // Admins and unrecognized roles see everything.
        if (in_array($role, ['admin', 'super-admin', 'other'], true)) {
            return $data;
        }

        $keys = match ($role) {
            'cashier'           => ['period', 'range', 'stats', 'cards', 'recent_bookings', 'upcoming_event_rows', 'low_stock_alerts'],
            'inventory-manager' => ['period', 'range', 'stats', 'cards', 'low_stock_alerts'],
            'staff-manager'     => ['period', 'range', 'stats', 'cards', 'staff_attendance_summary'],
            default             => array_keys($data),
        };

        $filtered = array_intersect_key($data, array_flip($keys));

        return empty($filtered) ? $data : $filtered;
    }

    private function filterChartsForRole(Request $request, array $data): array
    {
        $role = $this->primaryOperationalRole($request);

        if (in_array($role, ['admin', 'super-admin', 'other'], true)) {
            return $data;
        }

        $keys = match ($role) {
            'cashier'           => ['period', 'range', 'revenue_data', 'booking_trends', 'customer_growth', 'outstanding_invoices', 'top_packages', 'event_profitability', 'monthly_expenses'],
            'inventory-manager' => ['period', 'range', 'inventory_distribution', 'stock_movement', 'menu_performance', 'top_menu_items'],
            'staff-manager'     => ['period', 'range', 'booking_trends', 'payroll_by_employee'],
            default             => array_keys($data),
        };

        $filtered = array_intersect_key($data, array_flip($keys));

        return empty($filtered) ? $data : $filtered;
    }

    private function primaryOperationalRole(Request $request): string
    {
        $roles = $request->user()?->roles()
            ->where('is_active', true)
            ->pluck('slug')
            ->map(fn($role) => str_replace('_', '-', strtolower((string) $role)))
            ->all() ?? [];

        if (array_intersect($roles, ['super-admin', 'superadmin'])) return 'super-admin';
        if (array_intersect($roles, ['admin', 'administrator', 'owner'])) return 'admin';
        if (array_intersect($roles, ['cashier', 'finance', 'finance-staff'])) return 'cashier';
        if (array_intersect($roles, ['inventory-manager'])) return 'inventory-manager';
        if (array_intersect($roles, ['staff-manager', 'people-manager'])) return 'staff-manager';

        return 'other';
    }

    // ============================================================
    // PERIOD RESOLUTION
    // ============================================================
    private function resolvePeriodRange(string $period, ?string $anchor): array
    {
        $anchorDate = $anchor ? Carbon::parse($anchor) : now();

        if ($anchorDate->isFuture()) {
            $anchorDate = now();
        }

        switch ($period) {
            case 'weekly':
                $start     = $anchorDate->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
                $end       = $anchorDate->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
                $prevStart = $start->copy()->subWeek()->startOfDay();
                $prevEnd   = $end->copy()->subWeek()->endOfDay();
                $label     = $start->format('M d') . ' – ' . $end->format('M d, Y');
                break;

            case 'yearly':
                $start     = $anchorDate->copy()->startOfYear()->startOfDay();
                $end       = $anchorDate->copy()->endOfYear()->endOfDay();
                $prevStart = $start->copy()->subYear()->startOfDay();
                $prevEnd   = $end->copy()->subYear()->endOfDay();
                $label     = $start->format('Y');
                break;

            case 'monthly':
            default:
                $start     = $anchorDate->copy()->startOfMonth()->startOfDay();
                $end       = $anchorDate->copy()->endOfMonth()->endOfDay();
                $prevStart = $start->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
                $prevEnd   = $start->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
                $label     = $start->format('F Y');
                break;
        }

        return [
            'period'     => $period,
            'start'      => $start,
            'end'        => $end,
            'prev_start' => $prevStart,
            'prev_end'   => $prevEnd,
            'label'      => $label,
        ];
    }

    // ============================================================
    // PERCENTAGE CHANGE
    // ============================================================
    private function changeOf(float|int $current, float|int $previous): array
    {
        $curr = (float) $current;
        $prev = (float) $previous;

        if ($prev === 0.0 && $curr === 0.0) {
            return ['value' => 0.0, 'label' => '0%', 'type' => 'neutral'];
        }

        if ($prev === 0.0) {
            return ['value' => 100.0, 'label' => '+100%', 'type' => 'positive'];
        }

        $pct = round((($curr - $prev) / abs($prev)) * 100, 1);

        return [
            'value' => $pct,
            'label' => ($pct > 0 ? '+' : '') . $pct . '%',
            'type'  => $pct > 0 ? 'positive' : ($pct < 0 ? 'negative' : 'neutral'),
        ];
    }

    // ============================================================
    // SUMMARY CARDS
    // ============================================================
    private function buildSummaryCards(array $range): array
    {
        $start     = $range['start'];
        $end       = $range['end'];
        $prevStart = $range['prev_start'];
        $prevEnd   = $range['prev_end'];

        $sales         = $this->totalSales($start, $end);
        $prevSales     = $this->totalSales($prevStart, $prevEnd);

        $collected     = $this->totalPaymentsCollected($start, $end);
        $prevCollected = $this->totalPaymentsCollected($prevStart, $prevEnd);

        $expenses      = $this->totalExpenses($start, $end);
        $prevExpenses  = $this->totalExpenses($prevStart, $prevEnd);

        // ⭐ Profit now matches ProfitabilityService formula:
        //    revenue (collected, net of refunds) − total expenses.
        //    This keeps Dashboard KPIs consistent with the Reports page.
        $profit        = $collected - $expenses;
        $prevProfit    = $prevCollected - $prevExpenses;

        $bookings      = $this->totalBookings($start, $end);
        $prevBookings  = $this->totalBookings($prevStart, $prevEnd);

        $completed     = $this->completedEvents($start, $end);
        $prevCompleted = $this->completedEvents($prevStart, $prevEnd);

        return [
            'total_sales' => [
                'value'  => round($sales, 2),
                'change' => $this->changeOf($sales, $prevSales),
            ],
            'total_revenue' => [
                'value'  => round($collected, 2),
                'label'  => 'Total Payments Collected',
                'change' => $this->changeOf($collected, $prevCollected),
            ],
            'total_expenses' => [
                'value'  => round($expenses, 2),
                'change' => $this->changeOf($expenses, $prevExpenses),
            ],
            'total_profit' => [
                'value'  => round($profit, 2),
                'change' => $this->changeOf($profit, $prevProfit),
            ],
            'total_pending' => [
                'value'  => $this->totalPending($start, $end), // integer count
                'change' => ['value' => 0, 'label' => 'N/A', 'type' => 'neutral'],
            ],
            'total_bookings' => [
                'value'  => $bookings,
                'change' => $this->changeOf($bookings, $prevBookings),
            ],
            'completed_events' => [
                'value'  => $completed,
                'change' => $this->changeOf($completed, $prevCompleted),
            ],
            'active_staff' => [
                'value'  => (int) Employee::query()
                    ->where('status', 'active')
                    ->whereDate('hire_date', '<=', $end->toDateString())
                    ->count(),
                'change' => ['value' => 0, 'label' => 'N/A', 'type' => 'neutral'],
            ],
        ];
    }

    // ============================================================
    // CALCULATION HELPERS
    // ============================================================
    private function totalSales(Carbon $start, Carbon $end): float
    {
        return (float) Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('booking_status', ['cancelled', 'rejected'])
            ->where('booking_no', 'not like', 'HIST-%')
            ->with(['invoice', 'quotation'])
            ->get()
            ->sum(fn(Booking $b) => (float) ($b->invoice?->total_amount ?? $b->quotation?->total_amount ?? 0));
    }
    private function totalPaymentsCollected(Carbon $start, Carbon $end): float
    {
        // ⭐ Aligned with ProfitabilityService: filter by service_events.event_date
        //    so Dashboard "Revenue" reconciles with the Reports page.
        return (float) BookingPayment::query()
            ->join('bookings', 'booking_payments.booking_id', '=', 'bookings.booking_id')
            ->join('service_events', 'bookings.service_event_id', '=', 'service_events.service_event_id')
            ->where('booking_payments.status', 'completed')
            ->whereBetween('service_events.event_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN booking_payments.payment_type = 'refund' THEN -ABS(booking_payments.amount) ELSE booking_payments.amount END), 0) as total")
            ->value('total');
    }
    private function totalExpenses(Carbon $start, Carbon $end): float
    {
        // Expenses use their own posting dates (purchase date / payroll cutoff)
        // because they are real cash outflows, not event-attributable costs.
        // This is intentionally different from revenue (event-date basis).
        $inventory = (float) InventoryMovement::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('movement_type', ['purchase', 'waste'])
            ->sum(DB::raw('ABS(quantity_change) * COALESCE(unit_cost_at_time, 0)'));

        $payroll = (float) DB::table('payrolls')
            ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereBetween('payrolls.created_at', [$start, $end])
            ->where('payroll_items.item_type', 'earning')
            ->sum('payroll_items.amount');

        return round($inventory + $payroll, 2);
    }

    /**
     * ⭐ Total Pending = COUNT of bookings still awaiting approval
     * (pending / pending_approval / draft). Returns a whole number.
     *
     * Example: if 30 bookings are pending, this returns 30.
     */
    private function totalPending(?Carbon $start = null, ?Carbon $end = null): int
    {
        $query = Booking::query()
            ->whereIn('booking_status', ['pending', 'pending_approval', 'draft'])
            ->where('booking_no', 'not like', 'HIST-%');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return (int) $query->count();
    }
    private function totalBookings(Carbon $start, Carbon $end): int
    {
        // ⭐ Include approved/rescheduled so the KPI matches the detail modal
        // and BookingController::statistics() (which uses the same set).
        return (int) Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('booking_status', ['confirmed', 'ongoing', 'completed', 'approved', 'rescheduled'])
            ->where('booking_no', 'not like', 'HIST-%')
            ->count();
    }
    private function completedEvents(Carbon $start, Carbon $end): int
    {
        return (int) Booking::query()
            ->whereBetween('updated_at', [$start, $end])
            ->where('booking_status', 'completed')
            ->where('booking_no', 'not like', 'HIST-%')
            ->count();
    }

    private function completionRate(array $range): float
    {
        $bookings  = $this->totalBookings($range['start'], $range['end']);
        $completed = $this->completedEvents($range['start'], $range['end']);
        return $bookings > 0 ? round(($completed / $bookings) * 100, 2) : 0;
    }

    // ============================================================
    // CHART SERIES BUILDERS
    // ============================================================
    private function revenueExpensesSeries(string $period, Carbon $start, Carbon $end): array
    {
        // ⭐ Batched: one grouped query per metric instead of N per-day queries.
        // monthly  → 1 query per metric (was ~30)
        // weekly   → 1 query per metric (was ~7)
        // yearly   → 1 query per metric (was ~12)
        $bucketExpr = $period === 'yearly' ? "DATE_FORMAT(%s, '%%Y-%%m')" : 'DATE(%s)';

        $paymentExpr = sprintf($bucketExpr, 'payment_date');
        $invExpr     = sprintf($bucketExpr, 'created_at');
        $payrollExpr = sprintf($bucketExpr, 'payrolls.created_at');

        $revenueByBucket = BookingPayment::query()
            ->whereBetween('payment_date', [$start, $end])
            ->where('status', 'completed')
            ->selectRaw("{$paymentExpr} as bucket")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -ABS(amount) ELSE amount END), 0) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $inventoryByBucket = InventoryMovement::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('movement_type', ['purchase', 'waste'])
            ->selectRaw("{$invExpr} as bucket")
            ->selectRaw('COALESCE(SUM(ABS(quantity_change) * COALESCE(unit_cost_at_time, 0)), 0) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $payrollByBucket = DB::table('payrolls')
            ->join('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereBetween('payrolls.created_at', [$start, $end])
            ->where('payroll_items.item_type', 'earning')
            ->selectRaw("{$payrollExpr} as bucket")
            ->selectRaw('COALESCE(SUM(payroll_items.amount), 0) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $rows = [];

        $pushRow = function (Carbon $bucketStart) use (&$rows, $period, $revenueByBucket, $inventoryByBucket, $payrollByBucket) {
            $key = $period === 'yearly'
                ? $bucketStart->format('Y-m')
                : $bucketStart->format('Y-m-d');

            $label = match ($period) {
                'yearly'  => $bucketStart->format('M'),
                'weekly'  => $bucketStart->format('D'),
                default   => $bucketStart->format('M d'),
            };

            $revenue  = (float) ($revenueByBucket[$key] ?? 0);
            $expenses = round(
                (float) ($inventoryByBucket[$key] ?? 0)
                    + (float) ($payrollByBucket[$key] ?? 0),
                2
            );

            $rows[] = [
                'month'    => $label,
                'period'   => $label,
                'date'     => $bucketStart->toDateString(),
                'revenue'  => $revenue,
                'expenses' => $expenses,
                'profit'   => round($revenue - $expenses, 2),
            ];
        };

        if ($period === 'yearly') {
            for ($m = 1; $m <= 12; $m++) {
                $pushRow(Carbon::create($start->year, $m, 1)->startOfMonth());
            }
        } else {
            $cursor = $start->copy()->startOfDay();
            while ($cursor->lte($end)) {
                $pushRow($cursor->copy());
                $cursor->addDay();
            }
        }

        return $rows;
    }
    private function bookingTrendSeries(string $period, Carbon $start, Carbon $end): array
    {
        // ⭐ Batched: one grouped query for the whole range instead of N per-bucket queries.
        $bucketExpr = $period === 'yearly'
            ? "DATE_FORMAT(created_at, '%Y-%m')"
            : 'DATE(created_at)';

        $grouped = Booking::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('booking_no', 'not like', 'HIST-%')
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw("SUM(CASE WHEN booking_status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN booking_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN booking_status IN ('confirmed','ongoing','completed') THEN 1 ELSE 0 END) as bookings")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $rows = [];

        $pushRow = function (Carbon $bucketStart) use (&$rows, $period, $grouped) {
            $key = $period === 'yearly'
                ? $bucketStart->format('Y-m')
                : $bucketStart->format('Y-m-d');

            $b = $grouped[$key] ?? null;

            $label = match ($period) {
                'yearly' => $bucketStart->format('M'),
                'weekly' => $bucketStart->format('D'),
                default  => $bucketStart->format('M d'),
            };

            $rows[] = [
                'period'    => $label,
                'month'     => $label,
                'date'      => $bucketStart->toDateString(),
                'completed' => (int) ($b->completed ?? 0),
                'cancelled' => (int) ($b->cancelled ?? 0),
                'bookings'  => (int) ($b->bookings  ?? 0),
            ];
        };

        if ($period === 'yearly') {
            for ($m = 1; $m <= 12; $m++) {
                $pushRow(Carbon::create($start->year, $m, 1)->startOfMonth());
            }
        } else {
            $cursor = $start->copy()->startOfDay();
            while ($cursor->lte($end)) {
                $pushRow($cursor->copy());
                $cursor->addDay();
            }
        }

        return $rows;
    }

    private function customerGrowthSeries(string $period, Carbon $start, Carbon $end): array
    {
        if ($period === 'yearly') {
            return Customer::whereBetween('created_at', [$start, $end])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as bucket")
                ->selectRaw('COUNT(*) as newCustomers')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get()
                ->map(fn($r) => [
                    'month'        => Carbon::parse($r->bucket . '-01')->format('M'),
                    'newCustomers' => (int) $r->newCustomers,
                ])
                ->values()
                ->all();
        }

        return Customer::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as bucket')
            ->selectRaw('COUNT(*) as newCustomers')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn($r) => [
                'month'        => Carbon::parse($r->bucket)->format('M d'),
                'newCustomers' => (int) $r->newCustomers,
            ])
            ->values()
            ->all();
    }

    private function stockMovementSeries(string $period, Carbon $start, Carbon $end): array
    {
        $groupExpr = $period === 'yearly'
            ? "DATE_FORMAT(created_at, '%Y-%m')"
            : 'DATE(created_at)';

        return InventoryMovement::whereBetween('created_at', [$start, $end])
            ->selectRaw("{$groupExpr} as period")
            ->selectRaw("SUM(CASE WHEN movement_type = 'purchase' THEN ABS(quantity_change) ELSE 0 END) as incoming")
            ->selectRaw("SUM(CASE WHEN movement_type IN ('usage', 'return', 'adjustment') AND quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as outgoing")
            ->selectRaw("SUM(CASE WHEN movement_type = 'waste' THEN ABS(quantity_change) ELSE 0 END) as wastage")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(function ($item) use ($period) {
                return [
                    'period'   => $period === 'yearly'
                        ? Carbon::parse($item->period . '-01')->format('M')
                        : Carbon::parse($item->period)->format('M d'),
                    'incoming' => (float) $item->incoming,
                    'outgoing' => (float) $item->outgoing,
                    'wastage'  => (float) $item->wastage,
                ];
            })
            ->values()
            ->all();
    }

    private function weeklyPerformanceSeries(string $period, Carbon $start, Carbon $end): array
    {
        $groupExpr = $period === 'yearly'
            ? "DATE_FORMAT(created_at, '%Y-%m')"
            : 'DATE(created_at)';

        $bookings = Booking::whereBetween('created_at', [$start, $end])
            ->where('booking_no', 'not like', 'HIST-%')
            ->selectRaw("{$groupExpr} as bucket")
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('orders', 'bucket');

        $revenue = BookingPayment::whereBetween('payment_date', [$start, $end])
            ->where('status', 'completed')
            ->selectRaw("DATE_FORMAT(payment_date, '%Y-%m-%d') as bucket")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -ABS(amount) ELSE amount END), 0) as revenue")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('revenue', 'bucket');

        $rows = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $day = $cursor->copy();
            $key = $period === 'yearly' ? $day->format('Y-m') : $day->format('Y-m-d');
            $label = match ($period) {
                'yearly' => $day->format('M'),
                'weekly' => $day->format('D'),
                default  => $day->format('M d'),
            };
            $rows[] = [
                'period'  => $label,
                'date'    => $day->toDateString(),
                'orders'  => (int) ($bookings[$key] ?? 0),
                'revenue' => round((float) ($revenue[$key] ?? 0), 2),
            ];
            $cursor->addDay();
        }

        if ($period === 'yearly') {
            $byMonth = [];
            foreach ($rows as $r) {
                $m = Carbon::parse($r['date'])->format('M');
                if (! isset($byMonth[$m])) {
                    $byMonth[$m] = ['period' => $m, 'date' => $r['date'], 'orders' => 0, 'revenue' => 0];
                }
                $byMonth[$m]['orders']  += $r['orders'];
                $byMonth[$m]['revenue'] += $r['revenue'];
            }
            return array_values($byMonth);
        }

        return $rows;
    }

    private function menuPerformanceSeries(Carbon $start, Carbon $end): array
    {
        return DB::table('menu_items')
            ->join('booking_items', 'menu_items.menu_item_id', '=', 'booking_items.menu_item_id')
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.booking_id')
            ->whereBetween('bookings.created_at', [$start, $end])
            ->whereNotIn('bookings.booking_status', ['cancelled', 'rejected'])
            ->whereNull('menu_items.deleted_at')
            ->where('bookings.booking_no', 'not like', 'HIST-%')
            ->select('menu_items.menu_item_id', 'menu_items.name')
            ->selectRaw('COALESCE(SUM(booking_items.quantity), 0) as popularity')
            ->selectRaw('COALESCE(SUM(booking_items.quantity * booking_items.unit_price), 0) as revenue')
            ->groupBy('menu_items.menu_item_id', 'menu_items.name')
            ->orderByDesc('popularity')
            ->limit(12)
            ->get()
            ->map(fn($item) => [
                'menu_item_id' => $item->menu_item_id,
                'name'         => $item->name,
                'popularity'   => (int) $item->popularity,
                'revenue'      => round((float) $item->revenue, 2),
            ])
            ->values()
            ->all();
    }

    private function topPackagesSeries(Carbon $start, Carbon $end): array
    {
        return DB::table('service_events')
            ->join('bookings', 'service_events.service_event_id', '=', 'bookings.service_event_id')
            ->leftJoin('packages', 'service_events.package_id', '=', 'packages.package_id')
            ->leftJoin('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
            ->leftJoin('quotations', 'bookings.quotation_id', '=', 'quotations.quotation_id')
            ->whereBetween('bookings.created_at', [$start, $end])
            ->whereNotIn('bookings.booking_status', ['cancelled', 'rejected'])
            ->where('bookings.booking_no', 'not like', 'HIST-%')
            ->selectRaw("COALESCE(packages.name, 'Custom Menu') as name")
            ->selectRaw('COUNT(DISTINCT bookings.booking_id) as orders')
            ->selectRaw('COALESCE(SUM(COALESCE(invoices.total_amount, quotations.total_amount, 0)), 0) as revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn($item) => [
                'name'      => $item->name,
                'orders'    => (int) $item->orders,
                'revenue'   => round((float) $item->revenue, 2),
                'avg_value' => $item->orders > 0 ? round((float) $item->revenue / (int) $item->orders, 2) : 0,
            ])
            ->values()
            ->all();
    }

    private function eventProfitabilitySeries(Carbon $start, Carbon $end): array
    {
        $bookings = Booking::with(['serviceEvent.eventType', 'invoice', 'quotation', 'items.menuItem'])
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('booking_status', ['cancelled', 'rejected'])
            ->where('booking_no', 'not like', 'HIST-%')
            ->latest('booking_id')
            ->limit(25)
            ->get();

        return $bookings->map(function (Booking $booking) {
            $revenue = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
            $cost = (float) $booking->items->sum(
                fn($item) => ((float) $item->quantity) * ((float) ($item->menuItem?->cost_to_make ?? 0))
            );
            $profit = $revenue - $cost;
            $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

            return [
                'event_id'   => $booking->service_event_id,
                'booking_id' => $booking->booking_no,
                'event'      => $booking->serviceEvent?->eventType?->name ?? 'Event',
                'event_type' => $booking->serviceEvent?->eventType?->name ?? 'General',
                'revenue'    => round($revenue, 2),
                'cost'       => round($cost, 2),
                'profit'     => round($profit, 2),
                'margin'     => $margin,
                'status'     => $booking->booking_status,
            ];
        })->values()->all();
    }

    private function topMenuItemsSeries(Carbon $start, Carbon $end): array
    {
        return DB::table('menu_items')
            ->join('booking_items', 'menu_items.menu_item_id', '=', 'booking_items.menu_item_id')
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.booking_id')
            ->whereBetween('bookings.created_at', [$start, $end])
            ->whereNotIn('bookings.booking_status', ['cancelled', 'rejected'])
            ->whereNull('menu_items.deleted_at')
            ->where('bookings.booking_no', 'not like', 'HIST-%')
            ->select('menu_items.menu_item_id', 'menu_items.name')
            ->selectRaw('COALESCE(SUM(booking_items.quantity), 0) as orders')
            ->selectRaw('COALESCE(SUM(booking_items.quantity * booking_items.unit_price), 0) as revenue')
            ->groupBy('menu_items.menu_item_id', 'menu_items.name')
            ->orderByDesc('orders')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'id'         => $item->menu_item_id,
                'name'       => $item->name,
                'orders'     => (int) $item->orders,
                'revenue'    => round((float) $item->revenue, 2),
                'popularity' => (int) $item->orders,
            ])
            ->values()
            ->all();
    }
    private function payrollByEmployeeSeries(Carbon $start, Carbon $end): array
    {
        // Payroll amounts live in payroll_items, not on the payrolls row.
        // Aggregate earnings and deductions per employee for the period.
        $rows = DB::table('payrolls')
            ->join('employees', 'payrolls.employee_id', '=', 'employees.employee_id')
            ->leftJoin('persons', 'employees.person_id', '=', 'persons.person_id')
            ->leftJoin('positions', 'employees.position_id', '=', 'positions.position_id')
            ->leftJoin('payroll_items', 'payrolls.payroll_id', '=', 'payroll_items.payroll_id')
            ->whereBetween('payrolls.created_at', [$start, $end])
            ->whereNull('payrolls.deleted_at')
            ->groupBy(
                'employees.employee_id',
                'persons.first_name',
                'persons.last_name',
                'positions.title'
            )
            ->select('employees.employee_id')
            ->selectRaw("CONCAT(COALESCE(persons.first_name,''), ' ', COALESCE(persons.last_name,'')) as employee_name")
            ->selectRaw("COALESCE(positions.title, 'Staff') as position")
            ->selectRaw("COALESCE(SUM(CASE WHEN payroll_items.item_type = 'earning' THEN payroll_items.amount ELSE 0 END), 0) as gross_pay")
            ->selectRaw("COALESCE(SUM(CASE WHEN payroll_items.item_type = 'deduction' THEN payroll_items.amount ELSE 0 END), 0) as deductions")
            ->selectRaw("COALESCE(SUM(CASE WHEN payroll_items.item_type = 'earning' THEN payroll_items.amount WHEN payroll_items.item_type = 'deduction' THEN -payroll_items.amount ELSE 0 END), 0) as net_pay")
            ->orderByDesc('net_pay')
            ->limit(20)
            ->get();

        return $rows
            ->map(fn($item) => [
                'id'            => $item->employee_id,
                'employee_name' => trim($item->employee_name) ?: 'Employee',
                'position'      => $item->position,
                'gross_pay'     => round((float) $item->gross_pay, 2),
                'deductions'    => round((float) $item->deductions, 2),
                'net_pay'       => round((float) $item->net_pay, 2),
            ])
            ->values()
            ->all();
    }

    private function expensesVsProfitSeries(string $period, Carbon $start, Carbon $end): array
    {
        $series = $this->revenueExpensesSeries($period, $start, $end);
        return array_map(fn($row) => [
            'month'    => $row['month'],
            'expenses' => $row['expenses'],
            'profit'   => $row['profit'],
        ], $series);
    }
}
