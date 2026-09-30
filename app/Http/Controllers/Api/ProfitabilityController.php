<?php
// app/Http/Controllers/Api/ProfitabilityController.php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Services\ProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfitabilityController extends Controller
{
    protected ProfitabilityService $profitabilityService;

    public function __construct(ProfitabilityService $profitabilityService)
    {
        $this->profitabilityService = $profitabilityService;
    }

    /**
     * Get profitability for a specific booking.
     */
    public function show(Booking $booking, Request $request): JsonResponse
    {
        try {
            $booking->load([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'items.menuItem',
                'mealServices.menuItem',
                'payments',
                'invoice',
                'quotation',
                'charges',
                'equipment.equipment',
            ]);

            $useSnapshot = $request->boolean('use_snapshot', true);
            $profitability = $this->profitabilityService->getProfitability($booking, $useSnapshot);

            // Add booking details for display
            $profitability['booking'] = [
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
                'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                'event_type' => $booking->serviceEvent?->eventType?->name,
                'event_date' => $booking->serviceEvent?->event_date?->toDateString(),
                'event_time' => $booking->serviceEvent?->event_time,
                'venue' => $booking->serviceEvent?->venue,
                'pax' => (int) ($booking->serviceEvent?->guests_count ?? 0),
                'booking_status' => $booking->booking_status,
            ];

            return $this->ok($profitability);
        } catch (\Exception $e) {
            Log::error('Get booking profitability error: ' . $e->getMessage(), [
                'booking_id' => $booking->booking_id,
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to calculate profitability: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create/save a cost snapshot for a booking.
     */
    public function saveSnapshot(Booking $booking, Request $request): JsonResponse
    {
        try {
            $snapshotType = $request->input('snapshot_type', 'projected');

            $profitability = $this->profitabilityService->calculateProfitability($booking, true, $snapshotType);
            $snapshot = $this->profitabilityService->saveSnapshot($booking, $profitability, $snapshotType);

            return $this->ok([
                'snapshot_id' => $snapshot->id,
                'profitability' => $profitability,
            ], 'Cost snapshot saved successfully.');
        } catch (\Exception $e) {
            Log::error('Save cost snapshot error: ' . $e->getMessage());
            return $this->fail('Failed to save snapshot: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get aggregate profitability report.
     */
    public function report(Request $request): JsonResponse
    {
        try {
            $filters = [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'event_type_id' => $request->input('event_type_id'),
                'statuses' => $request->input('statuses', ['completed', 'confirmed', 'ongoing']),
            ];

            $report = $this->profitabilityService->getAggregateReport($filters);

            return $this->ok($report);
        } catch (\Exception $e) {
            Log::error('Profitability report error: ' . $e->getMessage());
            return $this->fail('Failed to generate report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get menu performance report.
     */
    public function menuPerformance(Request $request): JsonResponse
    {
        try {
            $filters = [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'statuses' => $request->input('statuses', ['completed', 'confirmed', 'ongoing']),
            ];

            $data = $this->profitabilityService->getMenuPerformance($filters);

            return $this->ok($data);
        } catch (\Exception $e) {
            Log::error('Menu performance error: ' . $e->getMessage());
            return $this->fail('Failed to get menu performance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get dashboard KPIs.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $today = now()->toDateString();
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();

            // ⭐ Revenue today — event-date basis, excludes cancelled/rejected.
            $revenueToday = (float) Booking::query()
                ->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', $today))
                ->whereNotIn('booking_status', ['cancelled', 'rejected'])
                ->where('booking_no', 'not like', 'HIST-%')
                ->join('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
                ->sum('invoices.total_amount');

            // ⭐ Revenue this month — same basis for consistency.
            $revenueMonth = (float) Booking::query()
                ->whereHas('serviceEvent', fn($q) => $q->whereBetween('event_date', [$monthStart, $monthEnd]))
                ->whereNotIn('booking_status', ['cancelled', 'rejected'])
                ->where('booking_no', 'not like', 'HIST-%')
                ->join('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
                ->sum('invoices.total_amount');

            // ⭐ Total profit for the current month — scoped to the same
            //    period so the KPI reconciles with the Profitability report.
            $monthBookings = Booking::query()
                ->whereHas('serviceEvent', fn($q) => $q->whereBetween('event_date', [$monthStart, $monthEnd]))
                ->whereIn('booking_status', ['completed', 'confirmed', 'ongoing'])
                ->where('booking_no', 'not like', 'HIST-%')
                ->with([
                    'serviceEvent',
                    'payments',
                    'invoice',
                    'quotation',
                    'charges',
                    'items.menuItem.recipeIngredients.ingredient',
                ])
                ->get();

            $totalProfit = 0;
            $totalRevenueForMargin = 0;
            foreach ($monthBookings as $booking) {
                $profitability = $this->profitabilityService->getProfitability($booking, true);
                $totalProfit += (float) ($profitability['profit'] ?? 0);
                $totalRevenueForMargin += (float) ($profitability['total_revenue']
                    ?? $profitability['revenue']
                    ?? 0);
            }

            // ⭐ Outstanding payments — includes partial + unpaid invoices.
            $outstanding = (float) Booking::query()
                ->whereIn('booking_status', ['confirmed', 'completed', 'ongoing'])
                ->where('booking_no', 'not like', 'HIST-%')
                ->join('invoices', 'bookings.booking_id', '=', 'invoices.booking_id')
                ->whereRaw('invoices.paid_amount < invoices.total_amount')
                ->sum(DB::raw('GREATEST(invoices.total_amount - invoices.paid_amount, 0)'));
            $completedCount = $monthBookings->where('booking_status', 'completed')->count();
            $avgProfitMargin = $totalRevenueForMargin > 0
                ? round(($totalProfit / $totalRevenueForMargin) * 100, 2)
                : 0;

            return $this->ok([
                'revenue_today'              => round($revenueToday, 2),
                'revenue_this_month'         => round($revenueMonth, 2),
                'profit_this_month'          => round($totalProfit, 2),
                'total_profit'               => round($totalProfit, 2),
                'profit_margin'              => $avgProfitMargin,
                'completed_bookings'         => $completedCount,
                'outstanding_payments'       => round($outstanding, 2),
                'period'                     => [
                    'start' => $monthStart,
                    'end'   => $monthEnd,
                    'label' => now()->format('F Y'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage());
            return $this->fail('Failed to load dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save booking costs (manual override).
     */
    public function saveCosts(Booking $booking, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'labor_cost' => ['nullable', 'numeric', 'min:0'],
                'delivery_cost' => ['nullable', 'numeric', 'min:0'],
                'equipment_cost' => ['nullable', 'numeric', 'min:0'],
                'other_cost' => ['nullable', 'numeric', 'min:0'],
                'service_fee' => ['nullable', 'numeric', 'min:0'],
                'delivery_fee' => ['nullable', 'numeric', 'min:0'],
                'extras_revenue' => ['nullable', 'numeric', 'min:0'],
            ]);

            // Store in settings
            \App\Models\Setting::updateOrCreate(
                ['group' => 'booking_costs', 'key' => 'booking_' . $booking->booking_id],
                ['value' => json_encode($validated), 'type' => 'json']
            );

            // Recalculate with new values
            $profitability = $this->profitabilityService->calculateProfitability($booking, true, 'actual');

            return $this->ok($profitability, 'Costs updated successfully.');
        } catch (\Exception $e) {
            Log::error('Save costs error: ' . $e->getMessage());
            return $this->fail('Failed to save costs: ' . $e->getMessage(), 500);
        }
    }
}
