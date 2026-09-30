<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\ShiftType;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $query = Schedule::with([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'shiftTypeDefinition',
        ]);
        $this->excludeAnalyticsOnly($query, $request);

        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }

        if ($request->filled('date')) {
            $query->whereDate('work_date', $request->input('date'));
        }

        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->input('to'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->ok(
            $query->orderBy('work_date')->orderBy('start_time')->paginate($request->integer('per_page', 100))
        );
    }

    public function show(Schedule $schedule)
    {
        return $this->ok($schedule->load([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'shiftTypeDefinition',
        ]));
    }

    public function today(Request $request)
    {
        $request->merge(['date' => today()->toDateString()]);
        return $this->index($request);
    }

    public function week(Request $request)
    {
        $request->merge([
            'from' => $request->input('from', now()->startOfWeek()->toDateString()),
            'to' => $request->input('to', now()->endOfWeek()->toDateString()),
        ]);
        return $this->index($request);
    }

    public function month(Request $request)
    {
        $request->merge([
            'from' => $request->input('from', now()->startOfMonth()->toDateString()),
            'to' => $request->input('to', now()->endOfMonth()->toDateString()),
        ]);
        return $this->index($request);
    }

    public function archived(Request $request)
    {
        $request->merge(['archived' => true]);
        return $this->index($request);
    }

    public function employee(Request $request, $employeeId)
    {
        $request->merge(['employee_id' => $employeeId]);
        return $this->index($request);
    }

    public function date(Request $request, $date)
    {
        $request->merge(['date' => $date]);
        return $this->index($request);
    }

    public function range(Request $request)
    {
        return $this->index($request);
    }

    public function completed(Request $request)
    {
        $request->merge(['status' => 'completed']);
        return $this->index($request);
    }

    public function stats(Request $request)
    {
        $query = Schedule::query();
        $this->excludeAnalyticsOnly($query, $request);

        if ($request->filled('date')) {
            $query->whereDate('work_date', $request->input('date'));
        }

        return $this->ok([
            'total' => (clone $query)->count(),
            'scheduled' => (clone $query)->where('status', 'scheduled')->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'absent' => (clone $query)->where('status', 'absent')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ]);
    }

    public function warnings()
    {
        return $this->ok([]);
    }

    public function store(Request $request)
    {
        $payload = $this->validatedDatabasePayload($request);
        $this->assertAvailable($payload['employee_id'], $payload['work_date']);
        if ($warning = $this->approvedRequestWarning($payload['employee_id'], $payload['work_date'], $request)) {
            return $warning;
        }

        $schedule = Schedule::create($payload);
        AuditLog::log('schedule_created', AuditLog::MODULE_SCHEDULES, $schedule->schedule_id, null, $schedule->getAttributes());

        // ✅ Notification: Schedule assigned to employee
        $employee = $schedule->employee;
        if ($employee && $employee->user_id) {
            $this->notificationService->scheduleAssigned($schedule, $employee);
        }

        return $this->ok($schedule->load(['employee.person', 'employee.department', 'shiftTypeDefinition']), 'Schedule created');
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'schedules' => 'required|array|min:1',
            'schedules.*' => 'required|array',
        ]);

        $created = DB::transaction(fn () => collect($validated['schedules'])->map(function (array $row) {
            $rowRequest = Request::create('/', 'POST', $row);
            $payload = $this->validatedDatabasePayload($rowRequest);
            $this->assertAvailable($payload['employee_id'], $payload['work_date']);

            $schedule = Schedule::create($payload);
            AuditLog::log('schedule_created', AuditLog::MODULE_SCHEDULES, $schedule->schedule_id, null, $schedule->getAttributes());

            // ✅ Notification: Schedule assigned for each employee in bulk
            $employee = $schedule->employee;
            if ($employee && $employee->user_id) {
                $this->notificationService->scheduleAssigned($schedule, $employee);
            }

            return $schedule->load(['employee.person', 'employee.department', 'shiftTypeDefinition']);
        }));

        return $this->ok($created, 'Schedules created');
    }

    public function update(Request $request, Schedule $schedule)
    {
        $oldEmployeeId = $schedule->employee_id;
        $oldWorkDate = $schedule->work_date;
        $oldStatus = $schedule->status;
        $oldValues = $schedule->getAttributes();

        $payload = $this->validatedDatabasePayload($request, $schedule);
        $this->assertAvailable($payload['employee_id'], $payload['work_date'], $schedule->schedule_id);
        if ($warning = $this->approvedRequestWarning($payload['employee_id'], $payload['work_date'], $request)) {
            return $warning;
        }
        $schedule->update($payload);
        AuditLog::log('schedule_edited', AuditLog::MODULE_SCHEDULES, $schedule->schedule_id, $oldValues, $schedule->fresh()->getAttributes());

        // ✅ Notification: Schedule updated for employee
        $employee = $schedule->employee;
        if ($employee && $employee->user_id) {
            $changes = [];
            if ($oldEmployeeId != $payload['employee_id']) $changes[] = 'employee changed';
            if ($oldWorkDate != $payload['work_date']) $changes[] = 'date changed';
            if ($oldStatus != ($payload['status'] ?? $oldStatus)) $changes[] = 'status changed';

            if (!empty($changes)) {
                $this->notificationService->scheduleUpdated($schedule, $employee, $changes);
            }
        }

        return $this->ok($schedule->fresh(['employee.person', 'employee.department', 'shiftTypeDefinition']), 'Schedule updated');
    }

    public function destroy(Schedule $schedule)
    {
        $employee = $schedule->employee;
        $schedule->delete();

        // ✅ Notification: Schedule cancelled for employee
        if ($employee && $employee->user_id) {
            $this->notificationService->scheduleCancelled($schedule, $employee);
        }

        return $this->ok(null, 'Schedule archived');
    }

    public function restore($id)
    {
        $schedule = Schedule::onlyTrashed()->findOrFail($id);
        $schedule->restore();

        // ✅ Notification: Schedule restored for employee
        $employee = $schedule->employee;
        if ($employee && $employee->user_id) {
            $this->notificationService->notifyUser(
                $employee->user_id,
                'schedule_restored',
                'Schedule Restored',
                "Your schedule for {$schedule->work_date->format('M d, Y')} has been restored.",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['schedule_id' => $schedule->schedule_id]
            );
        }

        return $this->ok($schedule->fresh(['employee.person', 'employee.department', 'shiftTypeDefinition']), 'Schedule restored');
    }

    public function archive(Schedule $schedule)
    {
        return $this->destroy($schedule);
    }

    public function bulkArchive(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:schedules,schedule_id',
        ])['ids'];

        $schedules = Schedule::whereIn('schedule_id', $ids)->get();

        foreach ($schedules as $schedule) {
            $employee = $schedule->employee;
            if ($employee && $employee->user_id) {
                $this->notificationService->scheduleCancelled($schedule, $employee);
            }
        }

        Schedule::whereIn('schedule_id', $ids)->delete();

        return $this->ok(null, 'Schedules archived');
    }

    public function bulkRestore(Request $request)
    {
        $ids = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ])['ids'];

        $schedules = Schedule::onlyTrashed()->whereIn('schedule_id', $ids)->get();

        foreach ($schedules as $schedule) {
            $schedule->restore();
            $employee = $schedule->employee;
            if ($employee && $employee->user_id) {
                $this->notificationService->notifyUser(
                    $employee->user_id,
                    'schedule_restored',
                    'Schedule Restored',
                    "Your schedule for {$schedule->work_date->format('M d, Y')} has been restored.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['schedule_id' => $schedule->schedule_id]
                );
            }
        }

        return $this->ok(null, 'Schedules restored');
    }

    private function validatedDatabasePayload(Request $request, ?Schedule $existing = null): array
    {
        $request->merge([
            'work_date' => $request->input('work_date', $request->input('date', $existing?->work_date?->format('Y-m-d'))),
            'employee_id' => $request->input('employee_id', $existing?->employee_id),
            'start_time' => $request->input('start_time', $existing?->start_time),
            'end_time' => $request->input('end_time', $existing?->end_time),
        ]);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,employee_id',
            'shift_type_id' => 'nullable|exists:shift_types,shift_type_id',
            'shift_type' => 'nullable|string|max:50',
            'type' => 'nullable|string|max:50',
            'work_date' => 'required|date',
            'start_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'end_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'break_minutes' => 'nullable|numeric|min:0',
            'placement' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'assignment_details' => 'nullable',
            'status' => 'nullable|in:scheduled,in_progress,completed,absent,cancelled',
        ]);

        $hasNamedShiftType = array_key_exists('shift_type', $validated) || array_key_exists('type', $validated);
        $shiftTypeId = $validated['shift_type_id'] ?? null;

        if (! $shiftTypeId && ($hasNamedShiftType || ! $existing)) {
            $shiftTypeId = $this->resolveShiftTypeId(
                $validated['shift_type'] ?? $validated['type'] ?? 'regular',
                $validated['start_time'],
                $validated['end_time'],
                (float) ($validated['break_minutes'] ?? 0)
            );
        }

        $shiftTypeId = $shiftTypeId ?? $existing?->shift_type_id;

        return [
            'employee_id' => (int) $validated['employee_id'],
            'shift_type_id' => (int) $shiftTypeId,
            'work_date' => $validated['work_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'break_minutes' => (float) ($validated['break_minutes'] ?? $existing?->break_minutes ?? 0),
            'assignment_details' => $this->makeAssignmentDetails($validated, $existing),
            'status' => $validated['status'] ?? $existing?->status ?? 'scheduled',
        ];
    }

    private function makeAssignmentDetails(array $validated, ?Schedule $existing): ?string
    {
        if (array_key_exists('assignment_details', $validated)) {
            return is_array($validated['assignment_details'])
                ? json_encode($validated['assignment_details'])
                : $validated['assignment_details'];
        }

        $existingPayload = [];
        if ($existing?->assignment_details) {
            $decoded = json_decode($existing->assignment_details, true);
            $existingPayload = is_array($decoded) ? $decoded : ['notes' => $existing->assignment_details];
        }

        $payload = [
            'placement' => $validated['placement'] ?? ($existingPayload['placement'] ?? ''),
            'notes' => $validated['notes'] ?? ($existingPayload['notes'] ?? ''),
        ];

        return json_encode($payload);
    }

    private function resolveShiftTypeId(string $requestedType, string $startTime, string $endTime, float $breakMinutes): int
    {
        $slug = Str::slug(str_replace('_', '-', strtolower($requestedType)));
        $slug = $slug === 'on-call' ? 'oncall' : $slug;
        $slug = $slug ?: 'regular';

        $shiftType = ShiftType::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => Str::headline($slug),
                'default_start_time' => $startTime,
                'default_end_time' => $endTime,
                'break_minutes' => $breakMinutes,
                'night_differential_rate' => 0,
                'is_active' => true,
            ]
        );

        return (int) $shiftType->shift_type_id;
    }


    private function approvedRequestWarning(int $employeeId, string $workDate, Request $request)
    {
        if ($request->boolean('acknowledge_approved_request')) {
            return null;
        }

        $approvedRequest = LeaveRequest::with('employee.person')
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $workDate)
            ->whereDate('end_date', '>=', $workDate)
            ->first();

        if (! $approvedRequest) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This employee has an approved request on this date. Press OK to create/update the schedule anyway, or Cancel to follow the approved request.',
            'requires_request_acknowledgement' => true,
            'request' => $approvedRequest,
        ], 409);
    }


    private function excludeAnalyticsOnly($query, Request $request): void
    {
        if ($request->boolean('include_history') || ! Schema::hasColumn('schedules', 'booking_id')) {
            return;
        }

        $query->where(function ($scheduleQuery) {
            $scheduleQuery->whereNull('booking_id')
                ->orWhereHas(
                    'booking',
                    fn($bookingQuery) =>
                    $bookingQuery->where('booking_no', 'not like', 'HIST-%')
                );
        });
    }

    private function assertAvailable(int $employeeId, string $workDate, ?int $ignoreScheduleId = null): void
    {
        $query = Schedule::where('employee_id', $employeeId)->whereDate('work_date', $workDate);

        if ($ignoreScheduleId) {
            $query->where('schedule_id', '!=', $ignoreScheduleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'work_date' => 'This employee already has a schedule for the selected date.',
            ]);
        }
    }
}
