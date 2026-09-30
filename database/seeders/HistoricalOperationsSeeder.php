<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Schedule;
use App\Models\ShiftType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class HistoricalOperationsSeeder extends Seeder
{
    public function run(): void
    {
        $bookings = Booking::with('serviceEvent')
            ->where('booking_no', 'like', 'HIST-%')
            ->where('booking_status', 'completed')
            ->orderBy('booking_id')
            ->get();
        $employees = Employee::where('status', 'active')->get();
        if ($employees->isEmpty()) {
            $employees = Employee::all();
        }
        $equipment = Equipment::where('is_active', true)->get();
        $ingredients = Ingredient::where('is_active', true)->get();
        $userId = User::query()->value('user_id');

        if ($bookings->isEmpty() || $employees->isEmpty()) {
            $this->command?->warn('Historical operations skipped: historical bookings or employees are missing.');
            return;
        }

        $shift = ShiftType::where('is_active', true)->firstOrCreate(
            ['slug' => 'historical-event'],
            [
                'name' => 'Historical Event Shift',
                'default_start_time' => '08:00:00',
                'default_end_time' => '17:00:00',
                'break_minutes' => 60,
                'night_differential_rate' => 0,
                'is_active' => true,
            ]
        );

        foreach ($bookings as $bookingIndex => $booking) {
            $event = $booking->serviceEvent;
            if (! $event?->event_date) {
                continue;
            }

            $eventDate = Carbon::parse($event->event_date)->startOfDay();
            $existingHistoricalEmployeeIds = Schema::hasColumn('schedules', 'booking_id')
                ? Schedule::withTrashed()->where('booking_id', $booking->booking_id)->pluck('employee_id')
                : collect();

            $existingHistoricalEmployees = $employees->whereIn('employee_id', $existingHistoricalEmployeeIds);
            $freeEmployees = $employees->reject(function (Employee $employee) use ($eventDate, $existingHistoricalEmployeeIds) {
                if ($existingHistoricalEmployeeIds->contains($employee->employee_id)) {
                    return true;
                }

                $hasSchedule = Schedule::withTrashed()
                    ->where('employee_id', $employee->employee_id)
                    ->whereDate('work_date', $eventDate->toDateString())
                    ->exists();
                $hasAttendance = AttendanceLog::withTrashed()
                    ->where('employee_id', $employee->employee_id)
                    ->whereDate('attendance_date', $eventDate->toDateString())
                    ->exists();

                return $hasSchedule || $hasAttendance;
            });

            $assignedEmployees = $existingHistoricalEmployees
                ->concat($freeEmployees)
                ->unique('employee_id')
                ->take(2)
                ->values();

            foreach ($assignedEmployees as $offset => $employee) {
                $scheduleValues = [
                    'shift_type_id' => $shift->shift_type_id,
                    'start_time' => '08:00:00',
                    'end_time' => '17:00:00',
                    'break_minutes' => 60,
                    'assignment_details' => json_encode([
                        'placement' => $event->venue,
                        'notes' => "Historical analytics event {$booking->booking_no}",
                        'booking_id' => $booking->booking_id,
                        'analytics_only' => true,
                    ]),
                    'status' => 'completed',
                    'created_at' => $eventDate->copy()->subDays(7),
                    'updated_at' => $eventDate->copy()->endOfDay(),
                ];
                if (Schema::hasColumn('schedules', 'booking_id')) {
                    $scheduleValues['booking_id'] = $booking->booking_id;
                }

                $schedule = Schedule::withTrashed()->updateOrCreate(
                    [
                        'employee_id' => $employee->employee_id,
                        'work_date' => $eventDate->toDateString(),
                    ],
                    $scheduleValues
                );
                if ($schedule->trashed()) {
                    $schedule->restore();
                }

                $lateMinutes = (($bookingIndex + $offset) % 5 === 0) ? 12 : 0;
                $timeIn = $eventDate->copy()->setTime(8, $lateMinutes);
                $timeOut = $eventDate->copy()->setTime(17, (($bookingIndex + $offset) % 7 === 0) ? 30 : 0);
                $regularHours = 8.0;
                $overtimeHours = $timeOut->greaterThan($eventDate->copy()->setTime(17, 0)) ? 0.5 : 0.0;

                $attendance = AttendanceLog::withTrashed()->updateOrCreate(
                    [
                        'employee_id' => $employee->employee_id,
                        'attendance_date' => $eventDate->toDateString(),
                    ],
                    [
                        'schedule_id' => $schedule->schedule_id,
                        'time_in' => $timeIn,
                        'time_out' => $timeOut,
                        'break_start' => $eventDate->copy()->setTime(12, 0),
                        'break_end' => $eventDate->copy()->setTime(13, 0),
                        'face_verified' => true,
                        'device_info' => 'Historical analytics seeder',
                        'ip_address' => '127.0.0.1',
                        'status' => $lateMinutes > 0 ? 'late' : 'present',
                        'regular_hours' => $regularHours,
                        'overtime_hours' => $overtimeHours,
                        'undertime_hours' => 0,
                        'overtime_approved' => $overtimeHours > 0,
                        'approval_status' => 'approved',
                        'approved_by' => $userId,
                        'approved_at' => $eventDate->copy()->addDay(),
                        'approval_notes' => 'Analytics-only historical record',
                        'created_at' => $eventDate,
                        'updated_at' => $eventDate->copy()->addDay(),
                    ]
                );
                if ($attendance->trashed()) {
                    $attendance->restore();
                }
            }

            if ($equipment->isNotEmpty()) {
                $item = $equipment[$bookingIndex % $equipment->count()];
                BookingEquipment::updateOrCreate(
                    [
                        'booking_id' => $booking->booking_id,
                        'equipment_id' => $item->equipment_id,
                    ],
                    [
                        'quantity_reserved' => min(max(1, ($bookingIndex % 4) + 1), max(1, (int) $item->total_quantity)),
                        'quantity_used' => min(max(1, ($bookingIndex % 4) + 1), max(1, (int) $item->total_quantity)),
                        'quantity_damaged' => $bookingIndex % 19 === 0 ? 1 : 0,
                        'quantity_missing' => 0,
                        'rental_start_date' => $eventDate->toDateString(),
                        'rental_end_date' => $eventDate->copy()->addDay()->toDateString(),
                        'rental_price_at_booking' => 0,
                        'checked_out_date' => $eventDate->toDateString(),
                        'checked_in_date' => $eventDate->copy()->addDay()->toDateString(),
                        'condition_notes_out' => 'Good',
                        'condition_notes_in' => 'Returned after historical event',
                        'status' => 'returned',
                        'is_out_approved' => true,
                        'out_approved_at' => $eventDate->copy()->subDay(),
                        'checked_out_by' => 'Historical Analytics Seeder',
                        'returned_by' => 'Historical Analytics Seeder',
                        'return_notes' => 'Analytics-only historical record',
                    ]
                );
            }

            if ($ingredients->isNotEmpty() && $userId) {
                $ingredient = $ingredients[$bookingIndex % $ingredients->count()];
                $quantity = (float) (($bookingIndex % 8) + 2);
                InventoryMovement::updateOrCreate(
                    [
                        'ingredient_id' => $ingredient->ingredient_id,
                        'reference_type' => 'HistoricalAnalyticsBooking',
                        'reference_id' => $booking->booking_id,
                    ],
                    [
                        'performed_by' => $userId,
                        'movement_type' => 'usage',
                        'quantity_change' => -$quantity,
                        'quantity_before' => 100 + $quantity,
                        'quantity_after' => 100,
                        'unit_cost_at_time' => (float) ($ingredient->unit_cost ?? 0),
                        'reason' => "Analytics-only usage for {$booking->booking_no}",
                        'created_at' => $eventDate,
                        'updated_at' => $eventDate,
                    ]
                );
            }
        }

        $this->command?->info('Historical schedules, attendance, equipment usage, and inventory movements created.');
    }
}
