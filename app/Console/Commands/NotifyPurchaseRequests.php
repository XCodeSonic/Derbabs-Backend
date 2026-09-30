<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\PurchaseRequest;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyPurchaseRequests extends Command
{
    protected $signature = 'notify:purchase-requests';
    protected $description = 'Notify about pending purchase requests and create event ingredient purchase reminders 24-8 hours before events';

    public function handle(NotificationService $notificationService, InventoryService $inventoryService)
    {
        $created = 0;
        $now = now();
        $windowStart = $now->copy()->addHours(8);
        $windowEnd = $now->copy()->addHours(24);

        $bookings = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay'])
            ->whereIn('booking_status', ['approved', 'confirmed', 'ongoing'])
            ->whereHas('serviceEvent', function ($query) use ($windowStart, $windowEnd) {
                $query->whereDate('event_date', '>=', $windowStart->toDateString())
                    ->whereDate('event_date', '<=', $windowEnd->toDateString());
            })
            ->get()
            ->filter(fn (Booking $booking) => ! $inventoryService->hasDeductedInventory($booking));

        foreach ($bookings as $booking) {
            $eventDateTime = $this->eventDateTime($booking);
            if (! $eventDateTime || $eventDateTime->lessThan($windowStart) || $eventDateTime->greaterThan($windowEnd)) {
                continue;
            }

            $requirements = $inventoryService->getIngredientRequirements($booking);
            $shortages = collect($requirements)->where('need_to_buy', true)->values();
            if ($shortages->isEmpty()) {
                continue;
            }

            foreach ($shortages as $requirement) {
                $existing = PurchaseRequest::where('booking_id', $booking->booking_id)
                    ->where('ingredient_id', $requirement['ingredient_id'])
                    ->whereIn('status', ['pending', 'approved', 'ordered'])
                    ->first();

                if ($existing) {
                    continue;
                }

                PurchaseRequest::create([
                    'pr_number' => 'PRQ-' . now()->format('YmdHisv') . '-' . random_int(100, 999),
                    'booking_id' => $booking->booking_id,
                    'ingredient_id' => $requirement['ingredient_id'],
                    'quantity' => max(0.001, (float) ($requirement['shortage'] ?? 0)),
                    'urgency' => $eventDateTime->diffInHours($now) <= 8 ? 'critical' : 'urgent',
                    'status' => 'pending',
                    'expected_delivery' => $eventDateTime->copy()->subHours(4)->toDateString(),
                    'notes' => "Auto-generated reminder for {$booking->booking_no}: purchase before event on {$eventDateTime->toDateTimeString()}.",
                    'requested_by' => 1,
                ]);
                $created++;
            }

            $list = $shortages->take(5)->map(function ($row) {
                return "• {$row['name']}: {$row['shortage']} {$row['unit']}";
            })->implode("\n");

            $notificationService->notifyRole(
                'inventory_staff',
                'purchase_request_generated',
                '🛒 Ingredients Needed Before Event',
                "Booking {$booking->booking_no} needs ingredients within 24 hours:\n{$list}" . ($shortages->count() > 5 ? "\n+ " . ($shortages->count() - 5) . " more" : ''),
                \App\Models\Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                '/admin/inventory/purchase-requests'
            );
        }

        $pendingRequests = PurchaseRequest::where('status', 'pending')
            ->whereDate('created_at', '<=', Carbon::now()->subDay())
            ->with('ingredient')
            ->get();

        $groupedByUrgency = $pendingRequests->groupBy('urgency');

        foreach ($groupedByUrgency as $urgency => $requests) {
            $count = $requests->count();
            $requestList = $requests->take(5)->map(function ($r) {
                return "• {$r->ingredient?->name}: {$r->quantity} units";
            })->implode("\n");

            $priority = $urgency === 'critical' ? 'critical' : ($urgency === 'urgent' ? 'high' : 'medium');

            $notificationService->notifyRole(
                'inventory_staff',
                'purchase_requests_pending',
                "🛒 {$count} Purchase Request(s) Pending",
                "Pending purchase requests (Urgency: {$urgency}):\n{$requestList}" . ($requests->count() > 5 ? "\n+ " . ($requests->count() - 5) . " more" : ''),
                $priority,
                ['count' => $count, 'urgency' => $urgency],
                '/admin/inventory/purchase-requests'
            );
        }

        $this->info("Created {$created} event purchase reminder request(s). Notified about {$pendingRequests->count()} pending purchase request(s).");

        return Command::SUCCESS;
    }

    private function eventDateTime(Booking $booking): ?Carbon
    {
        $event = $booking->serviceEvent;
        if (! $event?->event_date) {
            return null;
        }

        try {
            $date = $event->event_date instanceof Carbon
                ? $event->event_date->toDateString()
                : Carbon::parse($event->event_date)->toDateString();
            $time = $event->event_time ?: '00:00:00';
            return Carbon::parse(trim($date . ' ' . $time));
        } catch (\Throwable $e) {
            Log::warning('Unable to parse event date/time for purchase reminder: ' . $e->getMessage());
            return null;
        }
    }
}
