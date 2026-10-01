<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AttendanceRequest;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Schedule;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceLog::with([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'schedule',
            'approver',
        ]);
        $this->excludeAnalyticsOnly($query, $request);

        if ($request->filled('employee_id')) {
            $employeeIdentifier = $request->input('employee_id');
            if (is_numeric($employeeIdentifier)) {
                $query->where('employee_id', $employeeIdentifier);
            } else {
                $query->whereHas('employee', function ($employeeQuery) use ($employeeIdentifier) {
                    $employeeQuery->where('employee_code', 'like', "%{$employeeIdentifier}%")
                        ->orWhereHas('person', function ($personQuery) use ($employeeIdentifier) {
                            $personQuery->where('first_name', 'like', "%{$employeeIdentifier}%")
                                ->orWhere('last_name', 'like', "%{$employeeIdentifier}%");
                        });
                });
            }
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($employeeQuery) => $employeeQuery->where('department_id', $request->input('department_id')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $this->normalizeApprovalStatus($request->input('approval_status')));
        }

        if ($request->filled('verification_status')) {
            $query->where('approval_status', $this->normalizeApprovalStatus($request->input('verification_status')));
        }

        if ($request->filled('date')) {
            $query->whereDate('attendance_date', $request->input('date'));
        }

        // ⭐ FIX #5: Honor year/month filters from the mobile app.
        if ($request->filled('year')) {
            $query->whereYear('attendance_date', $request->integer('year'));
        }

        if ($request->filled('month')) {
            $query->whereMonth('attendance_date', $request->integer('month'));
        }

        $from = $request->input('from', $request->input('start_date'));
        $to = $request->input('to', $request->input('end_date'));

        if ($from) {
            $query->whereDate('attendance_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('attendance_date', '<=', $to);
        }

        if ($request->boolean('today')) {
            $query->whereDate('attendance_date', today());
        }

        $attendance = $query->latest('attendance_id')->paginate($request->integer('per_page', 20));
        $attendanceService = app(AttendanceService::class);
        $attendance->setCollection($attendance->getCollection()->map(function (AttendanceLog $row) use ($attendanceService) {
            return $row->time_in && $row->time_out ? $attendanceService->recalculate($row) : $row;
        }));

        return $this->ok($attendance);
    }

    public function today(Request $request)
    {
        $request->merge(['today' => true]);

        return $this->index($request);
    }

    /**
     * ⭐ Operational listing — always includes history records so the
     *    web attendance page never hides freshly-created mobile records.
     */
    public function operationalIndex(Request $request)
    {
        $request->merge(['include_history' => true]);

        return $this->index($request);
    }

    public function needsApproval(Request $request)
    {
        $request->merge(['approval_status' => 'pending']);

        return $this->index($request);
    }

    public function employee(Request $request, Employee $employee)
    {
        $request->merge(['employee_id' => $employee->employee_id]);

        return $this->index($request);
    }

    public function summary(Request $request)
    {
        if ($request->filled('employee_id')) {
            return $this->employeeSummary($request);
        }

        $monthStart = $request->input('start_date', now()->startOfMonth()->toDateString());
        $monthEnd = $request->input('end_date', now()->endOfMonth()->toDateString());

        $todayQuery = AttendanceLog::query();
        $periodQuery = AttendanceLog::query();
        $this->excludeAnalyticsOnly($todayQuery, $request);
        $this->excludeAnalyticsOnly($periodQuery, $request);
        $todayQuery->whereDate('attendance_date', today());
        $periodQuery->whereBetween('attendance_date', [$monthStart, $monthEnd]);
        $pendingQuery = (clone $periodQuery)->where('approval_status', 'pending');
        $approvedCount = (clone $periodQuery)->where('approval_status', 'approved')->count();
        $rejectedCount = (clone $periodQuery)->where('approval_status', 'rejected')->count();
        $pendingCount = (clone $pendingQuery)->count();
        $decisionBase = $approvedCount + $rejectedCount + $pendingCount;

        return $this->ok([
            'total' => (clone $todayQuery)->count(),
            'present' => (clone $todayQuery)->whereIn('status', ['present', 'late'])->count(),
            'late' => (clone $todayQuery)->where('status', 'late')->count(),
            'unscheduled' => (clone $todayQuery)->where('status', 'unscheduled')->count(),
            'pending' => (clone $todayQuery)->where('approval_status', 'pending')->count(),

            'pending_approval_count' => $pendingCount,
            'approved_this_month' => $approvedCount,
            'declined_this_month' => $rejectedCount,
            'total_hours_pending' => round((float) (clone $pendingQuery)->sum('regular_hours') + (float) (clone $pendingQuery)->sum('overtime_hours'), 2),
            'employees_with_pending' => (clone $pendingQuery)->distinct('employee_id')->count('employee_id'),
            'approval_rate' => $decisionBase > 0 ? round(($approvedCount / $decisionBase) * 100, 1) : 0,
        ]);
    }

    public function statistics(Request $request)
    {
        $query = AttendanceLog::query();
        $this->excludeAnalyticsOnly($query, $request);

        if ($request->filled('year')) {
            $query->whereYear('attendance_date', $request->integer('year'));
        }

        if ($request->filled('month')) {
            $query->whereMonth('attendance_date', $request->integer('month'));
        }

        return $this->ok([
            'total' => (clone $query)->count(),
            'approved' => (clone $query)->where('approval_status', 'approved')->count(),
            'pending' => (clone $query)->where('approval_status', 'pending')->count(),
            'rejected' => (clone $query)->where('approval_status', 'rejected')->count(),
            'overtime_hours' => round((float) (clone $query)->sum('overtime_hours'), 2),
            'undertime_hours' => round((float) (clone $query)->sum('undertime_hours'), 2),
        ]);
    }

    public function timeIn(AttendanceRequest $request, AttendanceService $service)
    {
        return $this->ok($service->timeIn($this->resolveAttendanceEmployee($request), $request->validated()), 'Timed in');
    }

    public function timeOut(AttendanceRequest $request, AttendanceService $service)
    {
        return $this->ok($service->timeOut($this->resolveAttendanceEmployee($request), $request->validated()), 'Timed out');
    }

    /**
     * ⭐ FIX #9: Flag an attendance record as AWOL / Emergency Absent / Late In / On Leave.
     */
    public function flagAttendance(Request $request, AttendanceLog $attendance)
    {
        $validated = $request->validate([
            'flag' => 'required|in:awol,emergency_absent,on_leave,late_in,none',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $attendance->getAttributes();
        $flag = $validated['flag'] === 'none' ? null : $validated['flag'];

        $updates = [
            'attendance_flag' => $flag,
            'flag_notes' => $validated['notes'] ?? null,
            'flagged_by' => $flag ? auth()->id() : null,
            'flagged_at' => $flag ? now() : null,
        ];

        // AWOL / EA — clear any accidental time values.
        if (in_array($flag, ['awol', 'emergency_absent'], true)) {
            $updates['time_in'] = null;
            $updates['time_out'] = null;
            $updates['regular_hours'] = 0;
            $updates['overtime_hours'] = 0;
            $updates['overtime_approved'] = false;
            $updates['status'] = 'absent';
        }

        $attendance->update($updates);

        AuditLog::log('attendance_flagged', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, [
            'flag' => $flag,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->ok(
            $this->attendanceRecordPayload($attendance->fresh([
                'employee.person',
                'employee.department',
                'schedule',
            ])),
            $flag ? "Attendance flagged as {$flag}" : 'Attendance flag cleared'
        );
    }

    public function updateTimes(Request $request, AttendanceLog $attendance, AttendanceService $attendanceService)
    {
        $oldValues = $attendance->getAttributes();

        $data = $request->validate([
            'time_in' => 'nullable|date',
            'time_out' => 'nullable|date',
            'approval_status' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if (! $request->exists('time_in') && ! $request->exists('time_out')) {
            throw ValidationException::withMessages([
                'attendance' => 'Provide a time-in, time-out, or both to update the attendance record.',
            ]);
        }

        $updates = [];
        if ($request->exists('time_in')) {
            $updates['time_in'] = $request->filled('time_in') ? Carbon::parse($request->input('time_in')) : null;
        }
        if ($request->exists('time_out')) {
            $updates['time_out'] = $request->filled('time_out') ? Carbon::parse($request->input('time_out')) : null;
        }

        $effectiveTimeIn = array_key_exists('time_in', $updates) ? $updates['time_in'] : $attendance->time_in;
        $newDate = $effectiveTimeIn
            ? Carbon::parse($effectiveTimeIn)->toDateString()
            : $attendance->attendance_date?->toDateString();

        // ⭐ FIX: An attendance row belongs to ONE cutoff. Refuse any edit that
        //    would move it into a different cutoff. This is what keeps a
        //    September 1-15 row from silently drifting into August 16-31 or
        //    September 16-30.
        if ($newDate && $attendance->attendance_date) {
            [$currentStart, $currentEnd] = $this->cutoffForDate(
                $attendance->attendance_date->toDateString()
            );
            $inCurrentCutoff = $newDate >= $currentStart && $newDate <= $currentEnd;

            if (! $inCurrentCutoff) {
                throw ValidationException::withMessages([
                    'time_in' => "Cannot move this record out of its cutoff ({$currentStart} to {$currentEnd}). "
                        . "To change the cutoff, archive this row and create a new one.",
                ]);
            }
        }

        if ($newDate) {
            $updates['attendance_date'] = $newDate;
        }


        if (array_key_exists('approval_status', $data)) {
            $status = $this->normalizeApprovalStatus($data['approval_status']);
            $updates['approval_status'] = $status;
            $updates['approved_by'] = in_array($status, ['approved', 'rejected'], true) ? auth()->id() : null;
            $updates['approved_at'] = in_array($status, ['approved', 'rejected'], true) ? now() : null;
        }
        if (array_key_exists('notes', $data)) {
            $updates['approval_notes'] = $data['notes'];
        }

        $collision = null;
        if ($newDate) {
            $collision = AttendanceLog::where('employee_id', $attendance->employee_id)
                ->whereDate('attendance_date', $newDate)
                ->where('attendance_id', '!=', $attendance->attendance_id)
                ->first();
        }

        if ($collision) {
            $mergedCollisionId = $collision->attendance_id;

            DB::transaction(function () use ($attendance, $collision, $updates) {
                $mergedTimeIn = $this->earliestTime([
                    $attendance->time_in,
                    $collision->time_in,
                    $updates['time_in'] ?? null,
                ]);

                $mergedTimeOut = $this->latestTime([
                    $attendance->time_out,
                    $collision->time_out,
                    $updates['time_out'] ?? null,
                ]);

                $notesCandidates = array_values(array_filter([
                    $updates['approval_notes'] ?? null,
                    $attendance->approval_notes,
                    $collision->approval_notes,
                ], fn($v) => is_string($v) && trim($v) !== ''));
                $mergedNotes = $notesCandidates ? end($notesCandidates) : null;

                $statusRank = ['approved' => 3, 'pending' => 2, 'rejected' => 1];
                $statuses = [
                    $updates['approval_status'] ?? null,
                    $attendance->approval_status,
                    $collision->approval_status,
                ];
                $bestStatus = collect($statuses)
                    ->filter()
                    ->sortByDesc(fn($s) => $statusRank[$s] ?? 0)
                    ->first() ?: 'pending';

                $overtimeRequest = $attendance->overtimeRequest ?? $collision->overtimeRequest;

                $collision->overtimeRequest()->delete();
                $collision->delete();

                $attendance->fill(array_merge($updates, [
                    'time_in' => $mergedTimeIn,
                    'time_out' => $mergedTimeOut,
                    'approval_status' => $bestStatus,
                    'approval_notes' => $mergedNotes,
                    'approved_by' => $bestStatus === 'approved' ? auth()->id() : $attendance->approved_by,
                    'approved_at' => $bestStatus === 'approved' ? now() : $attendance->approved_at,
                    'overtime_approved' => false,
                    'payroll_ready_at' => null,
                    'payroll_ready_by' => null,
                    'payroll_ready_notes' => null,
                ]))->save();

                if ($overtimeRequest && (int) $overtimeRequest->attendance_id !== (int) $attendance->attendance_id) {
                    $overtimeRequest->update(['attendance_id' => $attendance->attendance_id]);
                }
            });

            $attendance = $attendance->fresh(['schedule', 'overtimeRequest']);
            if ($attendance->time_in && $attendance->time_out) {
                $attendance = $attendanceService->recalculate($attendance);
            }

            $attendance->load(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'schedule', 'overtimeRequest']);

            AuditLog::log(
                'attendance_time_edited_merged',
                AuditLog::MODULE_ATTENDANCE,
                $attendance->attendance_id,
                $oldValues,
                array_merge($attendance->getAttributes(), [
                    'merged_with_attendance_id' => $mergedCollisionId,
                ])
            );

            return $this->ok([
                'attendance' => $this->attendanceRecordPayload($attendance),
                'merged' => true,
                'merged_with_attendance_id' => $mergedCollisionId,
                'payroll_sync' => [
                    'synced' => false,
                    'message' => 'Attendance was merged with an existing record on the same date. Regenerate the cutoff before processing payroll.',
                ],
            ], 'Attendance merged with existing record on the same date');
        }

        DB::transaction(function () use ($attendance, $updates) {
            $attendance->overtimeRequest()->delete();
            $attendance->fill(array_merge($updates, [
                'overtime_approved' => false,
                'payroll_ready_at' => null,
                'payroll_ready_by' => null,
                'payroll_ready_notes' => null,
            ]))->save();
        });

        $attendance = $attendance->fresh(['schedule', 'overtimeRequest']);
        if ($attendance->time_in && $attendance->time_out) {
            $attendance = $attendanceService->recalculate($attendance);
        } else {
            $attendance->update([
                'regular_hours' => 0,
                'overtime_hours' => 0,
                'undertime_hours' => 0,
                'overtime_approved' => false,
            ]);
            $attendance = $attendance->fresh(['schedule', 'overtimeRequest']);
        }

        $attendance->load(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'schedule', 'overtimeRequest']);
        AuditLog::log('attendance_time_edited', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->getAttributes());

        return $this->ok([
            'attendance' => $this->attendanceRecordPayload($attendance),
            'payroll_sync' => ['synced' => false, 'message' => 'Attendance changed. Generate and save the cutoff again before payroll processing.'],
        ], 'Attendance times updated');
    }

    private function earliestTime(array $times)
    {
        $valid = array_values(array_filter($times, fn($t) => $t !== null));
        if (empty($valid)) {
            return null;
        }
        return collect($valid)->map(fn($t) => Carbon::parse($t))->sort()->first();
    }

    private function latestTime(array $times)
    {
        $valid = array_values(array_filter($times, fn($t) => $t !== null));
        if (empty($valid)) {
            return null;
        }
        return collect($valid)->map(fn($t) => Carbon::parse($t))->sortDesc()->first();
    }

    public function updateStatus(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $request->validate([
            'verification_status' => 'nullable|string',
            'approval_status' => 'nullable|string',
            'verification_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $status = $this->normalizeApprovalStatus(
            $request->input('verification_status', $request->input('approval_status', 'pending'))
        );

        if ($attendance->approval_status === $status) {
            return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Attendance already has this status');
        }

        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'approval_status' => 'Attendance approval status must be pending, approved, rejected, or verified.',
            ]);
        }

        $isReviewed = in_array($status, ['approved', 'rejected'], true);

        if ($status === 'approved' && $attendance->status !== 'absent' && (! $attendance->time_in || ! $attendance->time_out)) {
            throw ValidationException::withMessages([
                'attendance' => 'Missing time-in or time-out must be edited before approval.',
            ]);
        }

        $attendance->update([
            'approval_status' => $status,
            'approval_notes' => $request->input('verification_notes', $request->input('notes')),
            'approved_by' => $isReviewed ? auth()->id() : null,
            'approved_at' => $isReviewed ? now() : null,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);
        AuditLog::log($status === 'rejected' ? 'attendance_declined' : 'attendance_approved', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Attendance updated');
    }

    public function approve(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'overtime_confirmed' => 'nullable|boolean',
            'remove_overtime' => 'nullable|boolean',
            'approved_overtime_hours' => 'nullable|numeric|min:0',
            'overtime_reason' => 'nullable|string',
        ]);

        if ($attendance->approval_status === 'approved' && $attendance->overtimeRequest?->status !== 'pending') {
            return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Attendance is already approved');
        }

        if (! $attendance->schedule_id) {
            throw ValidationException::withMessages([
                'schedule' => 'This record has no schedule. Use the No Schedule approve or decline action.',
            ]);
        }
        if (! $attendance->time_in || ! $attendance->time_out) {
            throw ValidationException::withMessages([
                'attendance' => 'Complete the missing time-in or time-out before approving this record.',
            ]);
        }

        if (
            (float) $attendance->overtime_hours > 0
            && ! $request->boolean('remove_overtime')
            && ! $request->boolean('overtime_confirmed')
            && ! array_key_exists('approved_overtime_hours', $validated)
            && ! $attendance->overtimeRequest()->whereIn('status', ['approved', 'rejected'])->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This attendance has overtime. Please approve or decline the overtime before saving it for payroll.',
                'requires_overtime_confirmation' => true,
                'attendance_id' => $attendance->attendance_id,
                'overtime_hours' => (float) $attendance->overtime_hours,
            ], 409);
        }

        $attendanceUpdates = [
            'approval_status' => 'approved',
            'approval_notes' => $validated['notes'] ?? null,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ];

        if ($request->boolean('remove_overtime')) {
            $attendanceUpdates['overtime_hours'] = 0;
            $attendanceUpdates['overtime_approved'] = false;

            OvertimeRequest::updateOrCreate(
                ['attendance_id' => $attendance->attendance_id],
                [
                    'employee_id' => $attendance->employee_id,
                    'hours' => 0,
                    'reason' => $validated['overtime_reason'] ?? 'Overtime removed during attendance approval.',
                    'status' => 'rejected',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]
            );
        } elseif ($request->boolean('overtime_confirmed') || array_key_exists('approved_overtime_hours', $validated)) {
            $approvedHours = array_key_exists('approved_overtime_hours', $validated)
                ? min((float) $validated['approved_overtime_hours'], (float) $attendance->overtime_hours)
                : (float) $attendance->overtime_hours;

            $attendanceUpdates['overtime_hours'] = $approvedHours;
            $attendanceUpdates['overtime_approved'] = $approvedHours > 0;

            OvertimeRequest::updateOrCreate(
                ['attendance_id' => $attendance->attendance_id],
                [
                    'employee_id' => $attendance->employee_id,
                    'hours' => $approvedHours,
                    'reason' => $validated['overtime_reason'] ?? null,
                    'status' => $approvedHours > 0 ? 'approved' : 'rejected',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]
            );
        }

        $attendance->update($attendanceUpdates);
        AuditLog::log('attendance_approved', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Attendance approved');
    }

    public function unverify(AttendanceLog $attendance)
    {
        $attendance->update([
            'approval_status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);

        return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Attendance returned to pending');
    }

    public function approveUnscheduled(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $validated = $request->validate([
            'admin_notes' => 'nullable|string',
        ]);
        if ($attendance->approval_status === 'approved') {
            return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Unscheduled attendance is already approved');
        }

        $attendance->update([
            'approval_status' => 'approved',
            'approval_notes' => $validated['admin_notes'] ?? null,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);
        AuditLog::log('no_schedule_approved', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(['employee.person', 'employee.department', 'schedule']), 'Unscheduled attendance approved');
    }

    public function approveOvertime(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $validated = $request->validate([
            'approved_overtime_hours' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $approvedHours = min(
            (float) ($validated['approved_overtime_hours'] ?? $attendance->overtime_hours),
            (float) $attendance->overtime_hours
        );

        $attendance->update([
            'overtime_hours' => $approvedHours,
            'overtime_approved' => $approvedHours > 0,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);

        OvertimeRequest::updateOrCreate(
            ['attendance_id' => $attendance->attendance_id],
            [
                'employee_id' => $attendance->employee_id,
                'hours' => $approvedHours,
                'reason' => $validated['notes'] ?? null,
                'status' => $approvedHours > 0 ? 'approved' : 'rejected',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]
        );
        AuditLog::log('overtime_approved', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(), 'Overtime approved');
    }

    public function rejectOvertime(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $originalOvertimeHours = (float) $attendance->overtime_hours;

        $attendance->update([
            'overtime_hours' => 0,
            'overtime_approved' => false,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);

        OvertimeRequest::updateOrCreate(
            ['attendance_id' => $attendance->attendance_id],
            [
                'employee_id' => $attendance->employee_id,
                'hours' => $originalOvertimeHours,
                'reason' => $validated['reason'] ?? null,
                'status' => 'rejected',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]
        );
        AuditLog::log('overtime_declined', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(['overtimeRequest']), 'Overtime rejected');
    }

    public function approveUndertime(Request $request, AttendanceLog $attendance)
    {
        $validated = $request->validate([
            'approved_undertime_hours' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $attendance->getAttributes();
        $attendance->update([
            'undertime_hours' => min((float) $validated['approved_undertime_hours'], (float) $attendance->undertime_hours),
            'approval_notes' => $validated['notes'] ?? $attendance->approval_notes,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);
        AuditLog::log('undertime_edited', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, $attendance->fresh()->getAttributes());

        return $this->ok($attendance->fresh(['schedule']), 'Undertime reviewed');
    }

    public function rejectUndertime(Request $request, AttendanceLog $attendance)
    {
        $oldValues = $attendance->getAttributes();
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $originalUndertimeHours = (float) $attendance->undertime_hours;

        $attendance->update([
            'undertime_hours' => 0,
            'approval_notes' => $validated['reason'] ?? $attendance->approval_notes,
            'payroll_ready_at' => null,
            'payroll_ready_by' => null,
        ]);

        AuditLog::log('undertime_declined', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, $oldValues, [
            'original_undertime_hours' => $originalUndertimeHours,
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->ok($attendance->fresh(['schedule']), 'Undertime rejected');
    }

    public function bulkOvertimeDecision(Request $request, AttendanceService $attendanceService)
    {
        $data = $request->validate([
            'attendance_ids' => 'required|array|min:1',
            'attendance_ids.*' => 'integer|distinct|exists:attendance_logs,attendance_id',
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string',
        ]);

        $updated = collect();
        $skipped = collect();

        DB::transaction(function () use ($data, $attendanceService, $updated, $skipped) {
            $records = AttendanceLog::with(['schedule', 'overtimeRequest'])
                ->whereIn('attendance_id', $data['attendance_ids'])
                ->lockForUpdate()
                ->get();

            foreach ($records as $attendance) {
                if (! $attendance->time_in || ! $attendance->time_out) {
                    $skipped->push(['attendance_id' => $attendance->attendance_id, 'reason' => 'Missing time-in or time-out.']);
                    continue;
                }

                $attendance = $attendanceService->recalculate($attendance);
                $computedHours = (float) $attendance->overtime_hours;
                if ($computedHours <= 0) {
                    $skipped->push(['attendance_id' => $attendance->attendance_id, 'reason' => 'No overtime beyond nine hours.']);
                    continue;
                }

                $isApproved = $data['action'] === 'approve';
                $attendance->update([
                    'overtime_hours' => $isApproved ? $computedHours : 0,
                    'overtime_approved' => $isApproved,
                    'payroll_ready_at' => null,
                    'payroll_ready_by' => null,
                ]);

                OvertimeRequest::updateOrCreate(
                    ['attendance_id' => $attendance->attendance_id],
                    [
                        'employee_id' => $attendance->employee_id,
                        'hours' => $computedHours,
                        'reason' => $data['reason'] ?? ($isApproved ? 'Bulk approved by administrator.' : 'Bulk declined by administrator.'),
                        'status' => $isApproved ? 'approved' : 'rejected',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]
                );
                AuditLog::log($isApproved ? 'overtime_approved' : 'overtime_declined', AuditLog::MODULE_ATTENDANCE, $attendance->attendance_id, null, [
                    'approved_hours' => $isApproved ? $computedHours : 0,
                    'eligible_hours' => $computedHours,
                    'bulk' => true,
                ]);

                $updated->push($attendance->fresh(['schedule', 'overtimeRequest']));
            }
        });

        return $this->ok([
            'updated_count' => $updated->count(),
            'skipped_count' => $skipped->count(),
            'records' => $updated->map(fn(AttendanceLog $row) => $this->attendanceRecordPayload($row))->values(),
            'skipped' => $skipped->values(),
        ], $data['action'] === 'approve' ? 'Selected overtime approved' : 'Selected overtime declined');
    }

    public function employeeOverview(Request $request, AttendanceService $attendanceService)
    {
        [$start, $end] = $this->attendancePeriod($request);

        $query = Employee::with(['person', 'department', 'position.salaryGrade'])
            ->where(function ($employeeQuery) use ($start, $end) {
                $employeeQuery->whereHas('attendanceLogs', function ($attendanceQuery) use ($start, $end) {
                    $attendanceQuery->whereBetween('attendance_date', [$start, $end]);
                })->orWhereHas('schedules', function ($scheduleQuery) use ($start, $end) {
                    $scheduleQuery->whereBetween('work_date', [$start, $end])
                        ->where('status', '!=', 'cancelled');
                });
            });

        if ($request->filled('department_id') && $request->input('department_id') !== 'all') {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('employee_id') && $request->input('employee_id') !== 'all') {
            $identifier = (string) $request->input('employee_id');
            $query->where(function ($employeeQuery) use ($identifier) {
                if (is_numeric($identifier)) {
                    $employeeQuery->orWhere('employee_id', (int) $identifier);
                }

                $employeeQuery->orWhere('employee_code', 'like', "%{$identifier}%")
                    ->orWhereHas('person', function ($personQuery) use ($identifier) {
                        $personQuery->where('first_name', 'like', "%{$identifier}%")
                            ->orWhere('last_name', 'like', "%{$identifier}%")
                            ->orWhere('email', 'like', "%{$identifier}%");
                    });
            });
        }

        $employees = $query
            ->orderBy('employee_id')
            ->get()
            ->map(function (Employee $employee) use ($start, $end, $attendanceService) {
                // ⭐ FIX: Do NOT materialize AWOL rows here. This endpoint is a
                //    read-only overview for the Process Payroll modal. Creating
                //    rows during a GET mutates payroll_ready_at state and makes
                //    the modal claim nothing was saved. Materialization already
                //    happens in employeeRecords() and generateSummary() where
                //    the user explicitly asked for it.
                // $attendanceService->materializeScheduledAbsences($employee->employee_id, $start, $end);

                $records = $this->attendanceHistoryRowsForEmployee($employee->employee_id, $start, $end)
                    ->map(function (AttendanceLog $attendance) use ($attendanceService) {
                        if ($attendance->time_in && $attendance->time_out) {
                            $attendance = $attendanceService->recalculate($attendance);
                        }
                        return $this->attendanceRecordPayload($attendance);
                    })
                    ->values();

                $summary = $this->summaryFromRecords($records);

                // ⭐ FIX #7: Cutoff insight counts
                $awolCount = $records->where('attendance_flag', 'awol')->count();
                $eaCount = $records->where('attendance_flag', 'emergency_absent')->count();
                $leaveCount = $records->where('attendance_flag', 'on_leave')->count();
                $lateInCount = $records->where('attendance_flag', 'late_in')->count();
                $presentDaysCount = $records->where('attendance_state', 'Complete')->count();

                $payroll = Payroll::withTrashed()
                    ->where('employee_id', $employee->employee_id)
                    ->whereDate('cutoff_start', $start)
                    ->whereDate('cutoff_end', $end)
                    ->first();

                $lastProcessedAt = $payroll
                    ? ($payroll->calculated_at ?? $payroll->updated_at ?? null)
                    : null;

                $allApproved = $records->isNotEmpty() && $records->every(
                    fn(array $row) => $this->normalizeApprovalStatus($row['approval_status'] ?? null) === 'approved'
                        && ($row['attendance_state'] ?? null) === 'Complete'
                        && ($row['overtime_status'] ?? null) !== 'pending'
                );

                // ⭐ FIX: "Saved to payroll" must be driven ONLY by payroll_ready_at,
                //    because that is what finalizeAttendanceForPayroll() sets.
                //    all_approved / attendance_state / overtime_status are display
                //    concerns and MUST NOT gate whether the employee appears in the
                //    Process Payroll modal.
                $savedCount = $records->filter(fn(array $row) => (bool) ($row['payroll_ready'] ?? false))->count();
                $unsavedCount = $records->count() - $savedCount;
                $allSaved = $records->isNotEmpty() && $unsavedCount === 0;

                return array_merge($summary, [
                    'employee_id' => $employee->employee_id,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => $employee->full_name ?: 'N/A',
                    'position' => $employee->position?->title ?? $employee->position?->name ?? 'N/A',
                    'department' => $employee->department?->name ?? 'N/A',
                    'generated' => false,
                    'all_approved' => $allApproved,
                    'saved_to_payroll' => $allSaved,
                    'unsaved_count' => $unsavedCount,
                    'saved_count' => $savedCount,
                    // ⭐ Explicit period echo so the frontend can verify the cutoff
                    //   it requested is the cutoff it received.
                    'period_start' => $start,
                    'period_end' => $end,
                    'payroll_status' => $payroll?->status,
                    'payroll_archived' => (bool) $payroll?->trashed(),
                    'payroll_id' => $payroll?->payroll_id,
                    'payroll_number' => $payroll?->payroll_number,
                    'payroll_updated_at' => $lastProcessedAt?->toIso8601String(),
                    'last_processed_at' => $lastProcessedAt?->toIso8601String(),

                    // ⭐ FIX #7: Insight counters for this cutoff
                    'awol_count' => $awolCount,
                    'emergency_absent_count' => $eaCount,
                    'on_leave_count' => $leaveCount,
                    'late_in_count' => $lateInCount,
                    'present_days_count' => $presentDaysCount,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                ]);
            })
            ->values();

        return $this->ok([
            'period_start' => $start,
            'period_end' => $end,
            'cutoff_start' => $start,
            'cutoff_end' => $end,
            'can_generate' => $this->canGenerateForPeriod($start, $end),
            'employees' => $employees,
        ]);
    }

    public function employeeRecords(Request $request, AttendanceService $attendanceService)
    {
        [$start, $end] = $this->attendancePeriod($request);
        $employee = $this->findAttendanceEmployee((string) $request->validate([
            'employee_id' => 'required',
        ])['employee_id']);

        $attendanceService->materializeScheduledAbsences($employee->employee_id, $start, $end);

        $records = $this->attendanceHistoryRowsForEmployee($employee->employee_id, $start, $end)
            ->map(function (AttendanceLog $attendance) use ($attendanceService) {
                if ($attendance->time_in && $attendance->time_out) {
                    $attendance = $attendanceService->recalculate($attendance);
                }
                return $this->attendanceRecordPayload($attendance);
            })
            ->values();

        return $this->ok([
            'employee' => $this->employeePayload($employee),
            'period_start' => $start,
            'period_end' => $end,
            'records' => $records,
            'summary' => $this->summaryFromRecords($records),
        ]);
    }

    public function generateSummary(Request $request, AttendanceService $service)
    {
        [$start, $end] = $this->attendancePeriod($request);
        $employee = $this->findAttendanceEmployee((string) $request->validate([
            'employee_id' => 'required',
        ])['employee_id']);

        DB::transaction(fn() => $service->materializeScheduledAbsences($employee->employee_id, $start, $end));

        $records = $this->attendanceRowsForEmployee($employee->employee_id, $start, $end)
            ->map(function (AttendanceLog $attendance) use ($service) {
                if ($attendance->time_in && $attendance->time_out) {
                    $attendance = $service->recalculate($attendance);
                    $attendance->load(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'schedule', 'overtimeRequest']);
                }

                return $this->attendanceRecordPayload($attendance);
            })
            ->values();

        $unresolved = $records->map(function (array $row) use ($employee) {
            $reason = null;
            if (($row['attendance_state'] ?? null) === 'No time in') {
                $reason = 'Missing Time In';
            } elseif (($row['attendance_state'] ?? null) === 'No time out') {
                $reason = 'Missing Time Out';
            } elseif ($this->normalizeApprovalStatus($row['approval_status'] ?? null) === 'pending') {
                if (($row['assigned_schedule'] ?? null) === 'Unscheduled') {
                    $reason = 'No Schedule';
                } elseif (empty($row['time_in_selfie_url']) || empty($row['time_out_selfie_url'])) {
                    $reason = 'No Selfie';
                } else {
                    $reason = 'Attendance approval pending';
                }
            } elseif (($row['overtime_status'] ?? null) === 'pending') {
                $reason = 'Overtime decision pending';
            }

            return $reason ? [
                'attendance_id' => $row['attendance_id'],
                'employee_id' => $employee->employee_id,
                'employee_name' => $employee->full_name,
                'date' => $row['date'],
                'reason' => $reason,
            ] : null;
        })->filter()->values();

        $payload = [
            'employee' => $this->employeePayload($employee),
            'period_start' => $start,
            'period_end' => $end,
            'can_generate' => $this->canGenerateForPeriod($start, $end),
            'records' => $records,
            'summary' => $this->summaryFromRecords($records),
            'unresolved_overtime_count' => $records->where('overtime_status', 'pending')->count(),
            'missing_time_count' => $records->filter(fn($row) => $row['attendance_state'] !== 'Complete')->count(),
            'generated' => $unresolved->isEmpty(),
            'unresolved' => $unresolved,
        ];

        if ($unresolved->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "There are still {$unresolved->count()} attendance records requiring action. Please review and resolve them before generating the final attendance record.",
                'data' => $payload,
            ], 409);
        }

        AuditLog::log('attendance_generated', AuditLog::MODULE_ATTENDANCE, $employee->employee_id, null, [
            'employee_id' => $employee->employee_id,
            'period_start' => $start,
            'period_end' => $end,
            'attendance_ids' => $records->pluck('attendance_id')->all(),
        ]);

        return $this->ok($payload, 'Attendance summary generated and verified');
    }

    public function saveSummaryToPayroll(Request $request, AttendanceService $attendanceService)
    {
        [$start, $end] = $this->attendancePeriod($request);
        $employee = $this->findAttendanceEmployee((string) $request->validate([
            'employee_id' => 'required',
            'notes' => 'nullable|string',
        ])['employee_id']);

        $summary = $this->finalizeAttendanceForPayroll(
            $employee,
            $start,
            $end,
            $request->input('notes'),
            $attendanceService
        );

        return $this->ok($summary, 'Attendance verification saved and marked ready for payroll');
    }

    public function saveAllSummariesToPayroll(Request $request, AttendanceService $attendanceService)
    {
        [$start, $end] = $this->attendancePeriod($request);
        $request->validate(['notes' => 'nullable|string']);

        // ⭐ FIX: Do NOT restrict to rows where payroll_ready_at IS NULL.
        //    If an employee already has some (or all) rows saved for this
        //    cutoff, they must still appear here so a partial save can be
        //    completed and so the response reflects the full cutoff.
        $employees = Employee::with(['person', 'department', 'position.salaryGrade'])
            ->whereHas('attendanceLogs', fn($query) => $query->whereBetween('attendance_date', [$start, $end]))
            ->orderBy('employee_id')
            ->get();

        $processed = collect();
        $skipped = collect();

        foreach ($employees as $employee) {
            try {
                $summary = $this->finalizeAttendanceForPayroll(
                    $employee,
                    $start,
                    $end,
                    $request->input('notes', 'Automatically saved from Attendance Employee Overview.'),
                    $attendanceService
                );
                $processed->push([
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->full_name,
                    'attendance_count' => $summary['attendance_count'],
                    'status' => 'payroll_ready',
                    'period_start' => $start,
                    'period_end' => $end,
                ]);
            } catch (ValidationException $exception) {
                $skipped->push([
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->full_name,
                    'reason' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
                $skipped->push([
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->full_name,
                    'reason' => 'Attendance verification could not be finalized for this employee.',
                ]);
            }
        }

        return $this->ok([
            'period_start' => $start,
            'period_end' => $end,
            'processed_count' => $processed->count(),
            'skipped_count' => $skipped->count(),
            'processed' => $processed->values(),
            'skipped' => $skipped->values(),
        ], $processed->isNotEmpty()
            ? "{$processed->count()} employee attendance summary record(s) marked ready for payroll."
            : 'No attendance summaries were ready to save.');
    }

    public function mobileLogin(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
        ]);

        $employee = $this->findAttendanceEmployee($data['employee_id']);

        return $this->ok(['employee' => $employee], 'Attendance employee selected');
    }

    public function checkMissingTimeouts(): JsonResponse
    {
        $yesterday = now()->subDay()->toDateString();

        $missingTimeouts = AttendanceLog::whereDate('attendance_date', $yesterday)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->with('employee')
            ->get();

        $notificationService = app(\App\Services\NotificationService::class);

        foreach ($missingTimeouts as $attendance) {
            if ($attendance->employee) {
                $notificationService->missingTimeoutAlert($attendance->employee, $attendance);
            }
        }

        return $this->ok(['notified' => $missingTimeouts->count()]);
    }

    public function mobileLogout()
    {
        return $this->ok(null, 'Attendance session cleared');
    }

    public function employeeSavedRecords(Request $request)
    {
        $request->validate([
            'employee_id' => 'required',
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2000,2100',
            'cutoff' => 'nullable|in:first,second',
        ]);

        $employee = $this->findAttendanceEmployee((string) $request->input('employee_id'));
        // ⭐ FIX: If the caller sends explicit start_date/end_date, trust those
        //    over month/year/cutoff. That way the Saved Records modal reads
        //    from exactly the same cutoff the Process Payroll modal displayed.
        $explicitStart = $request->input('start_date');
        $explicitEnd   = $request->input('end_date');

        if ($explicitStart && $explicitEnd) {
            $start = Carbon::parse($explicitStart)->startOfDay()->toDateString();
            $end   = Carbon::parse($explicitEnd)->endOfDay()->toDateString();
            $cutoff = $request->input('cutoff', 'first');
            $month = (int) Carbon::parse($start)->month;
            $year  = (int) Carbon::parse($start)->year;
        } else {
            $month = (int) ($request->input('month') ?? now()->month);
            $year = (int) ($request->input('year') ?? now()->year);
            $cutoff = $request->input('cutoff', 'first');

            if ($cutoff === 'second') {
                $start = Carbon::create($year, $month, 16)->startOfDay()->toDateString();
                $end = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay()->toDateString();
            } else {
                $start = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
                $end = Carbon::create($year, $month, 15)->endOfDay()->toDateString();
            }
        }

        $rows = AttendanceLog::with(['employee.person', 'schedule'])
            ->where('employee_id', $employee->employee_id)
            ->whereBetween('attendance_date', [$start, $end])
            ->whereNotNull('payroll_ready_at')
            ->orderBy('attendance_date')
            ->orderBy('time_in')
            ->get();

        $hourlyRate = (float) ($employee->hourly_rate
            ?: $employee->position?->salaryGrade?->default_hourly_rate
            ?: 0);

        $overtimeRate = $hourlyRate * 1.25;

        $totalRegularHours = 0.0;
        $totalOvertimeHours = 0.0;
        $totalUndertimeHours = 0.0;
        $totalHours = 0.0;
        $totalLateMinutes = 0;
        $totalUndertimeMinutes = 0;
        $totalLaborCost = 0.0;

        $records = $rows->map(function (AttendanceLog $row) use (
            $hourlyRate,
            $overtimeRate,
            &$totalRegularHours,
            &$totalOvertimeHours,
            &$totalUndertimeHours,
            &$totalHours,
            &$totalLateMinutes,
            &$totalUndertimeMinutes,
            &$totalLaborCost
        ) {
            $regular = round((float) $row->regular_hours, 2);
            $overtime = round((float) $row->overtime_hours, 2);
            $undertime = round((float) $row->undertime_hours, 2);
            $lateMinutes = (int) ($row->late_minutes ?? 0);
            $undertimeMinutes = (int) ($row->undertime_minutes ?? round($undertime * 60));

            $recordTotal = round($regular + $overtime, 2);

            $regularPay = round($regular * $hourlyRate, 2);
            $overtimePay = round($overtime * $overtimeRate, 2);
            $rowLaborCost = round($regularPay + $overtimePay, 2);

            $totalRegularHours += $regular;
            $totalOvertimeHours += $overtime;
            $totalUndertimeHours += $undertime;
            $totalHours += $recordTotal;
            $totalLateMinutes += $lateMinutes;
            $totalUndertimeMinutes += $undertimeMinutes;
            $totalLaborCost += $rowLaborCost;

            return [
                'attendance_id' => $row->attendance_id,
                'employee_id' => $row->employee_id,
                'day' => $row->attendance_date?->format('D'),
                'date' => $row->attendance_date?->toDateString(),
                'attendance_date' => $row->attendance_date?->toDateString(),
                'assigned_schedule' => $row->schedule_time,
                'time_in' => $row->time_in?->toIso8601String(),
                'time_out' => $row->time_out?->toIso8601String(),
                'formatted_time_in' => $row->formatted_time_in ?: 'No Time In',
                'formatted_time_out' => $row->formatted_time_out ?: 'No Time Out',
                'regular_hours' => $regular,
                'overtime_hours' => $overtime,
                'undertime_hours' => $undertime,
                'total_hours' => $recordTotal,
                'late_minutes' => $lateMinutes,
                'undertime_minutes' => $undertimeMinutes,
                'late_undertime' => trim("{$lateMinutes} min late / {$undertimeMinutes} min undertime"),
                'attendance_state' => $row->attendance_state,
                'attendance_flag' => $row->attendance_flag,
                'attendance_flag_label' => $row->attendance_flag_label,
                'overtime_status' => $this->overtimeStatus($row),
                'approval_status' => $row->approval_status,
                'verification_status' => $row->approval_status,
                'payroll_ready' => true,
                'payroll_ready_at' => $row->payroll_ready_at?->toIso8601String(),
                'saved_at' => $row->payroll_ready_at?->toIso8601String(),
                'hourly_rate' => $hourlyRate,
                'overtime_rate' => $overtimeRate,
                'regular_pay' => $regularPay,
                'overtime_pay' => $overtimePay,
                'labor_cost' => $rowLaborCost,
            ];
        })->values();

        $summary = [
            'total_records' => $records->count(),
            'total_regular_hours' => round($totalRegularHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'total_undertime_hours' => round($totalUndertimeHours, 2),
            'total_hours' => round($totalHours, 2),
            'total_late_minutes' => $totalLateMinutes,
            'total_undertime_minutes' => $totalUndertimeMinutes,
            'hourly_rate' => $hourlyRate,
            'overtime_rate' => $overtimeRate,
            'total_labor_cost' => round($totalLaborCost, 2),
        ];

        return $this->ok([
            'employee' => $this->employeePayload($employee),
            'period_start' => $start,
            'period_end' => $end,
            'cutoff' => $cutoff,
            'month' => $month,
            'year' => $year,
            'records' => $records,
            'count' => $records->count(),
            'summary' => $summary,
        ]);
    }

    public function createManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,employee_id',
            'date' => 'required|date',
            'time_in' => 'nullable|date_format:H:i',
            'time_out' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        $existing = AttendanceLog::where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $data['date'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This employee already has an attendance record for ' . $data['date'] . '.',
            ], 422);
        }

        $timeIn = $data['time_in'] ? Carbon::parse("{$data['date']} {$data['time_in']}") : null;
        $timeOut = $data['time_out'] ? Carbon::parse("{$data['date']} {$data['time_out']}") : null;

        $schedule = Schedule::where('employee_id', $employee->employee_id)
            ->whereDate('work_date', $data['date'])
            ->first();

        $log = AttendanceLog::create([
            'employee_id' => $employee->employee_id,
            'schedule_id' => $schedule?->schedule_id,
            'attendance_date' => $data['date'],
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'status' => $schedule ? 'present' : 'unscheduled',
            'approval_status' => 'pending',
            'device_info' => 'Admin manual entry',
            'approval_notes' => $data['notes'] ?? null,
        ]);

        if ($timeIn && $timeOut) {
            app(AttendanceService::class)->recalculate($log);
        }

        return $this->ok($log->fresh(['employee.person', 'schedule']), 'Attendance created');
    }

    private function employeeSummary(Request $request)
    {
        $employee = $this->findAttendanceEmployee($request->input('employee_id'));
        $today = today()->toDateString();
        $monthStart = $request->input('start_date', now()->startOfMonth()->toDateString());
        $monthEnd = $request->input('end_date', now()->endOfMonth()->toDateString());

        $todaySchedule = Schedule::with(['shiftTypeDefinition'])
            ->where('employee_id', $employee->employee_id)
            ->whereDate('work_date', $today)
            ->first();

        $todayAttendance = AttendanceLog::with(['schedule'])
            ->where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $today)
            ->latest('attendance_id')
            ->first();

        $period = AttendanceLog::where('employee_id', $employee->employee_id)
            ->whereBetween('attendance_date', [$monthStart, $monthEnd]);

        $approvedPeriod = (clone $period)->where('approval_status', 'approved');

        $todayStatus = 'not_started';
        if ($todayAttendance?->time_in && ! $todayAttendance?->time_out) {
            $todayStatus = 'timed_in';
        } elseif ($todayAttendance?->time_in && $todayAttendance?->time_out) {
            $todayStatus = 'completed';
        }

        return $this->ok([
            'employee' => $employee,
            'today_status' => $todayStatus,
            'today_schedule' => $todaySchedule,
            'today_attendance' => $todayAttendance,
            'present_days' => (clone $approvedPeriod)->whereIn('status', ['present', 'late', 'unscheduled'])->count(),
            'pending_approval_count' => (clone $period)->where('approval_status', 'pending')->count(),
            'approved_this_month' => (clone $period)->where('approval_status', 'approved')->count(),
            'current_month' => [
                'start_date' => $monthStart,
                'end_date' => $monthEnd,
                'present_days' => (clone $approvedPeriod)->whereIn('status', ['present', 'late', 'unscheduled'])->count(),
                'regular_hours' => round((float) (clone $approvedPeriod)->sum('regular_hours'), 2),
                'overtime_hours' => round((float) (clone $approvedPeriod)->where('overtime_approved', true)->sum('overtime_hours'), 2),
                'total_hours' => round(
                    (float) (clone $approvedPeriod)->sum('regular_hours')
                        + (float) (clone $approvedPeriod)->where('overtime_approved', true)->sum('overtime_hours'),
                    2
                ),
                'late_undertime_hours' => round((float) (clone $approvedPeriod)->sum('undertime_hours'), 2),
            ],
            'last_30_days' => AttendanceLog::with(['schedule'])
                ->where('employee_id', $employee->employee_id)
                ->whereDate('attendance_date', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('attendance_date')
                ->get(),
        ]);
    }

    private function resolveAttendanceEmployee(Request $request): Employee
    {
        $employeeIdentifier = $request->input('employee_id');

        if ($employeeIdentifier) {
            return $this->findAttendanceEmployee($employeeIdentifier);
        }

        $employee = $request->user()?->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee ID is required for attendance actions.',
            ]);
        }

        return $employee->load(['person', 'department', 'position.salaryGrade']);
    }

    private function findAttendanceEmployee(string $identifier): Employee
    {
        return Employee::with(['person', 'department', 'position.salaryGrade'])
            ->where('employee_id', $identifier)
            ->orWhere('employee_code', $identifier)
            ->firstOrFail();
    }

    private function attendancePeriod(Request $request): array
    {
        $request->merge([
            'start_date' => $request->input('start_date', $request->input('period_start')),
            'end_date' => $request->input('end_date', $request->input('period_end')),
        ]);

        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->endOfDay();

        // ⭐ FIX: Normalize the cutoff so a timezone-shifted start_date
        //    (e.g. '2025-09-15' coming from a UTC-converted Sept 16)
        //    cannot create a Payroll row in the wrong cutoff.
        //
        //    Rule: the cutoff ALWAYS starts on the 1st or the 16th.
        //    If the caller's start day is 15 but the end day is 30/31,
        //    they clearly meant the 16th-end cutoff — snap forward.
        //    If the caller's start day is 1 but the end day is 15,
        //    that's already correct.
        $startDay = (int) $start->day;
        $endDay = (int) $end->day;
        $endIsMonthEnd = $end->isSameDay($end->copy()->endOfMonth());

        if ($startDay === 15 && $endIsMonthEnd) {
            // Meant 16th → end of month
            $start = $start->copy()->addDay()->startOfDay();
        } elseif ($startDay === 16 && $endDay === 15) {
            // Meant 16th → end of month but end got shifted back a day
            $end = $end->copy()->endOfMonth()->endOfDay();
        } elseif ($startDay === 1 && $endDay === 14) {
            // Meant 1st → 15th but end got shifted back a day
            $end = $end->copy()->day(15)->endOfDay();
        }

        return [
            $start->toDateString(),
            $end->toDateString(),
        ];
    }
    /**
     * ⭐ FIX: This helper used to silently exclude rows whose payroll_ready_at
     *    was already set. That made the second save of a cutoff a no-op and
     *    caused the "saved then disappeared" symptom. Callers now receive
     *    every row in the period and decide what to do with it.
     */
    private function attendanceRowsForEmployee(int $employeeId, string $start, string $end)
    {
        return AttendanceLog::with([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'schedule',
            'overtimeRequest',
        ])
            ->where('employee_id', $employeeId)
            ->whereBetween('attendance_date', [$start, $end])
            ->orderBy('attendance_date')
            ->orderBy('time_in')
            ->get();
    }
    private function attendanceHistoryRowsForEmployee(int $employeeId, string $start, string $end)
    {
        return AttendanceLog::with([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'schedule',
            'overtimeRequest',
        ])
            ->where('employee_id', $employeeId)
            ->whereBetween('attendance_date', [$start, $end])
            ->orderBy('attendance_date')
            ->orderBy('time_in')
            ->get();
    }

    private function employeePayload(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->full_name ?: 'N/A',
            'position' => $employee->position?->title ?? $employee->position?->name ?? 'N/A',
            'department' => $employee->department?->name ?? 'N/A',
            'hourly_rate' => (float) ($employee->hourly_rate
                ?: $employee->position?->salaryGrade?->default_hourly_rate
                ?: 0),
            'bank_account_number' => $employee->bank_account_number
                ?? $employee->bank_account
                ?? $employee->account_number
                ?? null,
            'employee_type' => $employee->employee_type ?? $employee->employment_type ?? 'regular',
        ];
    }

    private function attendanceRecordPayload(AttendanceLog $attendance): array
    {
        $regular = round((float) $attendance->regular_hours, 2);
        $overtime = round((float) $attendance->overtime_hours, 2);
        $undertime = round((float) $attendance->undertime_hours, 2);
        $lateMinutes = (int) ($attendance->late_minutes ?? 0);
        $undertimeMinutes = (int) ($attendance->undertime_minutes ?? round($undertime * 60));

        return [
            'attendance_id' => $attendance->attendance_id,
            'employee_id' => $attendance->employee_id,
            'day' => $attendance->attendance_date?->format('D'),
            'date' => $attendance->attendance_date?->toDateString(),
            'assigned_schedule' => $attendance->schedule_time,
            'time_in' => $attendance->time_in?->toIso8601String(),
            'time_out' => $attendance->time_out?->toIso8601String(),
            'formatted_time_in' => $attendance->formatted_time_in ?: 'No Time In',
            'formatted_time_out' => $attendance->formatted_time_out ?: 'No Time Out',
            'time_in_selfie_url' => $attendance->time_in_selfie_url,
            'time_out_selfie_url' => $attendance->time_out_selfie_url,
            'time_in_location' => $attendance->time_in_latitude !== null && $attendance->time_in_longitude !== null
                ? ['lat' => (float) $attendance->time_in_latitude, 'lng' => (float) $attendance->time_in_longitude]
                : null,
            'time_out_location' => $attendance->time_out_latitude !== null && $attendance->time_out_longitude !== null
                ? ['lat' => (float) $attendance->time_out_latitude, 'lng' => (float) $attendance->time_out_longitude]
                : null,
            'regular_hours' => $regular,
            'overtime_hours' => $overtime,
            'undertime_hours' => $undertime,
            'total_hours' => round($regular + $overtime, 2),
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'late_undertime' => trim("{$lateMinutes} min late / {$undertimeMinutes} min undertime"),
            'attendance_state' => $attendance->attendance_state,
            'overtime_status' => $this->overtimeStatus($attendance),
            'approval_status' => $attendance->approval_status,
            'verification_status' => $attendance->approval_status,
            'payroll_ready' => (bool) $attendance->payroll_ready,
            'payroll_ready_at' => $attendance->payroll_ready_at?->toIso8601String(),
            'saved_at' => $attendance->payroll_ready_at?->toIso8601String(),
            'original_location' => $attendance->location,

            // ⭐ FIX #6, #7, #9 — flag data
            'attendance_flag' => $attendance->attendance_flag,
            'attendance_flag_label' => $attendance->attendance_flag_label,
            'flag_notes' => $attendance->flag_notes,
            'flagged_at' => $attendance->flagged_at?->toIso8601String(),
        ];
    }

    private function summaryFromRecords($records): array
    {
        $regularHours = round((float) $records->sum('regular_hours'), 2);
        $overtimeHours = round((float) $records->sum('overtime_hours'), 2);
        $lateMinutes = (int) $records->sum('late_minutes');
        $undertimeMinutes = (int) $records->sum('undertime_minutes');

        return [
            'regular_hours' => $regularHours,
            'overtime_hours' => $overtimeHours,
            'total_hours' => round($regularHours + $overtimeHours, 2),
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'late_undertime' => trim("{$lateMinutes} min late / {$undertimeMinutes} min undertime"),
        ];
    }

    private function overtimeStatus(AttendanceLog $attendance): string
    {
        $status = $attendance->overtimeRequest?->status;
        if (in_array($status, ['approved', 'rejected'], true)) {
            return $status;
        }

        if ((float) $attendance->overtime_hours <= 0) {
            return 'not_applicable';
        }

        if ((bool) $attendance->overtime_approved) {
            return 'approved';
        }

        return 'pending';
    }

    private function finalizeAttendanceForPayroll(
        Employee $employee,
        string $start,
        string $end,
        ?string $notes,
        AttendanceService $attendanceService
    ): array {
        // ⭐ FIX: Load ALL rows in the cutoff, saved or not. The caller may be
        //    re-finalizing after an edit, or completing a partial save. The
        //    only hard requirement is that the cutoff has at least one row.
        $attendance = AttendanceLog::with([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'schedule',
            'overtimeRequest',
        ])
            ->where('employee_id', $employee->employee_id)
            ->whereBetween('attendance_date', [$start, $end])
            ->orderBy('attendance_date')
            ->orderBy('time_in')
            ->get();

        if ($attendance->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->full_name} has no attendance records for {$start} to {$end}.",
            ]);
        }

        // ⭐ NEW: If every row is already finalized for this cutoff, surface a
        //    clear, non-silent message so the frontend can stop showing the
        //    employee as "ready to process".
        $alreadyFinalized = $attendance->every(fn(AttendanceLog $row) => ! is_null($row->payroll_ready_at));
        if ($alreadyFinalized) {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->full_name}'s attendance is already saved as payroll ready for {$start} to {$end}.",
            ]);
        }
        $missing = $attendance->first(fn(AttendanceLog $row) => $row->status !== 'absent' && (! $row->time_in || ! $row->time_out));
        if ($missing) {
            $state = ! $missing->time_in ? 'No Time In' : 'No Time Out';
            throw ValidationException::withMessages([
                'attendance' => "Cannot save to payroll. {$employee->full_name} has {$state} on {$missing->attendance_date?->toDateString()}.",
            ]);
        }

        $attendance = $attendance->map(function (AttendanceLog $row) use ($attendanceService) {
            $row = $attendanceService->recalculate($row);
            $row->load(['overtimeRequest']);
            return $row;
        });

        $notApproved = $attendance->first(
            fn(AttendanceLog $row) => $this->normalizeApprovalStatus($row->approval_status) === 'pending'
        );
        if ($notApproved) {
            throw ValidationException::withMessages([
                'attendance' => "Cannot save attendance. Resolve the pending record for {$notApproved->attendance_date?->toDateString()} first.",
            ]);
        }

        $unresolved = $attendance->first(fn(AttendanceLog $row) => $this->overtimeStatus($row) === 'pending');
        if ($unresolved) {
            throw ValidationException::withMessages([
                'overtime' => "Cannot save to payroll. Overtime on {$unresolved->attendance_date?->toDateString()} is still pending. Approve or decline it first.",
            ]);
        }

        return DB::transaction(function () use ($attendance, $employee, $start, $end, $notes) {
            $readyAt = now();
            $userId = auth()->id();

            $payroll = Payroll::withTrashed()->updateOrCreate(
                [
                    'employee_id' => $employee->employee_id,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                ],
                [
                    'payroll_number' => 'PR-'
                        . str_replace('-', '', $start) . '-'
                        . str_replace('-', '', $end) . '-'
                        . str_pad((string) $employee->employee_id, 4, '0', STR_PAD_LEFT),
                    'status' => 'calculated',
                    'payment_date' => now()->toDateString(),
                    'calculated_by' => $userId,
                    'calculated_at' => $readyAt,
                    'notes' => $notes,
                ]
            );

            if ($payroll->trashed()) {
                $payroll->restore();
            }

            foreach ($attendance as $row) {
                $snapshot = [
                    'attendance_id' => $row->attendance_id,
                    'employee_id' => $row->employee_id,
                    'attendance_date' => $row->attendance_date?->toDateString(),
                    'assigned_schedule' => $row->schedule_time,
                    'time_in' => $row->time_in?->toIso8601String(),
                    'time_out' => $row->time_out?->toIso8601String(),
                    'formatted_time_in' => $row->formatted_time_in,
                    'formatted_time_out' => $row->formatted_time_out,
                    'regular_hours' => (float) $row->regular_hours,
                    'overtime_hours' => (float) $row->overtime_hours,
                    'undertime_hours' => (float) $row->undertime_hours,
                    'total_hours' => (float) $row->total_hours,
                    'late_minutes' => (int) ($row->late_minutes ?? 0),
                    'undertime_minutes' => (int) ($row->undertime_minutes ?? 0),
                    'approval_status' => $row->approval_status ?? 'approved',
                    'overtime_status' => $this->overtimeStatus($row),
                    'attendance_state' => $row->attendance_state,
                    'attendance_flag' => $row->attendance_flag,
                ];

                $existing = $payroll->items()
                    ->where('item_name', 'Attendance Snapshot')
                    ->get()
                    ->first(function ($item) use ($row) {
                        $d = json_decode((string) $item->description, true);
                        return is_array($d) && (int) ($d['attendance_id'] ?? 0) === (int) $row->attendance_id;
                    });

                if ($existing) {
                    $existing->update(['description' => json_encode($snapshot)]);
                } else {
                    PayrollItem::create([
                        'payroll_id' => $payroll->payroll_id,
                        'item_type' => 'earning',
                        'item_name' => 'Attendance Snapshot',
                        'amount' => 0,
                        'description' => json_encode($snapshot),
                    ]);
                }
            }

            foreach ($attendance as $row) {
                $row->update([
                    'payroll_ready_at' => $readyAt,
                    'payroll_ready_by' => $userId,
                    'payroll_ready_notes' => $notes,
                ]);
            }

            AuditLog::log('attendance_saved', AuditLog::MODULE_ATTENDANCE, $employee->employee_id, null, [
                'employee_id' => $employee->employee_id,
                'period_start' => $start,
                'period_end' => $end,
                'payroll_id' => $payroll->payroll_id,
                'attendance_ids' => $attendance->pluck('attendance_id')->all(),
                'notes' => $notes,
            ]);

            return [
                'employee_id' => $employee->employee_id,
                'employee_name' => $employee->full_name,
                'payroll_id' => $payroll->payroll_id,
                'payroll_number' => $payroll->payroll_number,
                'period_start' => $start,
                'period_end' => $end,
                'attendance_count' => $attendance->count(),
                'payroll_ready_at' => $readyAt->toIso8601String(),
                'status' => 'payroll_ready',
                'attendance_ids' => $attendance->pluck('attendance_id')->values()->all(),
            ];
        });
    }

    private function attendanceSnapshotsForPayroll(Payroll $payroll): array
    {
        return $payroll->items()
            ->where('item_name', 'Attendance Snapshot')
            ->get()
            ->map(function ($item) {
                $data = json_decode((string) $item->description, true);
                return is_array($data) ? $data : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function saveAttendanceSnapshot(Payroll $payroll, AttendanceLog $attendance): void
    {
        $snapshot = [
            'attendance_id' => $attendance->attendance_id,
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'assigned_schedule' => $attendance->schedule_time,
            'time_in' => $attendance->time_in?->toIso8601String(),
            'time_out' => $attendance->time_out?->toIso8601String(),
            'formatted_time_in' => $attendance->formatted_time_in,
            'formatted_time_out' => $attendance->formatted_time_out,
            'regular_hours' => (float) $attendance->regular_hours,
            'overtime_hours' => (float) $attendance->overtime_hours,
            'undertime_hours' => (float) $attendance->undertime_hours,
            'total_hours' => (float) $attendance->total_hours,
            'late_minutes' => (int) ($attendance->late_minutes ?? 0),
            'undertime_minutes' => (int) ($attendance->undertime_minutes ?? 0),
            'approval_status' => $attendance->approval_status ?? 'approved',
            'overtime_status' => $this->overtimeStatus($attendance),
            'attendance_state' => $attendance->attendance_state,
            'attendance_flag' => $attendance->attendance_flag,
        ];

        $existing = $payroll->items()
            ->where('item_name', 'Attendance Snapshot')
            ->get()
            ->first(function ($item) use ($attendance) {
                $data = json_decode((string) $item->description, true);
                return is_array($data) && (int) ($data['attendance_id'] ?? 0) === (int) $attendance->attendance_id;
            });

        if ($existing) {
            $existing->update([
                'amount' => 0,
                'description' => json_encode($snapshot),
            ]);
        } else {
            PayrollItem::create([
                'payroll_id' => $payroll->payroll_id,
                'item_type' => 'earning',
                'item_name' => 'Attendance Snapshot',
                'amount' => 0,
                'description' => json_encode($snapshot),
            ]);
        }
    }

    private function cutoffForDate($date): array
    {
        $attendanceDate = Carbon::parse($date);
        if ($attendanceDate->day <= 15) {
            return [
                $attendanceDate->copy()->startOfMonth()->toDateString(),
                $attendanceDate->copy()->day(15)->toDateString(),
            ];
        }

        return [
            $attendanceDate->copy()->day(16)->toDateString(),
            $attendanceDate->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function isPayrollCutoff(string $start, string $end): bool
    {
        return true;
    }

    private function canGenerateForPeriod(string $start, string $end): bool
    {
        return true;
    }

    private function excludeAnalyticsOnly($query, Request $request): void
    {
        if ($request->boolean('include_history') || ! Schema::hasColumn('schedules', 'booking_id')) {
            return;
        }

        $query->where(function ($attendanceQuery) {
            $attendanceQuery->whereNull('schedule_id')
                ->orWhereHas('schedule', function ($scheduleQuery) {
                    $scheduleQuery->whereNull('booking_id')
                        ->orWhereHas(
                            'booking',
                            fn($bookingQuery) =>
                            $bookingQuery->where('booking_no', 'not like', 'HIST-%')
                        );
                });
        });
    }

    private function normalizeApprovalStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'verified', 'approve', 'approved' => 'approved',
            'declined', 'reject', 'rejected' => 'rejected',
            default => 'pending',
        };
    }
}
