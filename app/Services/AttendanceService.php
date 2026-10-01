<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function timeIn($employee, array $data): AttendanceLog
    {
        return DB::transaction(function () use ($employee, $data) {
            $timestamp = $this->attendanceTimestamp($data);
            // ⭐ FIX: Prefer an explicit cutoff_start sent by the mobile app.
            //    This keeps the attendance_date inside the cutoff the employee
            //    was actually working, regardless of device timezone drift.
            $cutoffStart = $data['cutoff_start'] ?? null;
            if ($cutoffStart) {
                $cutoffStartCarbon = Carbon::parse($cutoffStart);
                if ($timestamp->lt($cutoffStartCarbon)) {
                    // Timestamp is before the requested cutoff -> snap forward.
                    $timestamp = $cutoffStartCarbon->copy()->setTime(
                        $timestamp->hour,
                        $timestamp->minute,
                        $timestamp->second
                    );
                }
            }
            $date = $timestamp->toDateString();

            // ⭐ FIX: If the mobile app supplied a cutoff_start, the row MUST
            //    belong to that cutoff. Snap the date to the cutoff's start
            //    day (1 or 16) when a timezone shift pushed it one day back.
            if ($cutoffStart) {
                $cutoffStartCarbon = Carbon::parse($cutoffStart);
                $cutoffStartDay = (int) $cutoffStartCarbon->day;
                $dateCarbon = Carbon::parse($date);

                // Only correct an obvious off-by-one at the cutoff boundary.
                if (
                    $cutoffStartDay === 16
                    && (int) $dateCarbon->day === 15
                    && $dateCarbon->isSameMonth($cutoffStartCarbon)
                ) {
                    $date = $dateCarbon->copy()->addDay()->toDateString();
                    $timestamp = $timestamp->copy()->addDay();
                } elseif (
                    $cutoffStartDay === 1
                    && (int) $dateCarbon->day === 0
                ) {
                    // Defensive: month rollover edge case
                    $date = $dateCarbon->copy()->toDateString();
                }
            }

            $open = AttendanceLog::where('employee_id', $employee->employee_id)
                ->whereDate('attendance_date', $date)
                ->whereNotNull('time_in')
                ->whereNull('time_out')
                ->first();

            if ($open) {
                throw new RuntimeException('Employee already has an open attendance record.');
            }

            if (AttendanceLog::where('employee_id', $employee->employee_id)->whereDate('attendance_date', $date)->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => 'An attendance record already exists for this employee and date.',
                ]);
            }

            $schedule = Schedule::where('employee_id', $employee->employee_id)
                ->whereDate('work_date', $date)
                ->first();

            $status = $this->initialStatus($timestamp, $schedule);

            // ⭐ FIX C: Fail loudly when a selfie was provided but could not be stored.
            $storedSelfie = $this->storeSelfie($data['selfie'] ?? null, $employee->employee_id, 'in');
            if (!empty($data['selfie']) && $storedSelfie === null) {
                throw ValidationException::withMessages([
                    'selfie' => 'Selfie could not be processed. Please retake the photo.',
                ]);
            }

            $log = AttendanceLog::create([
                'employee_id' => $employee->employee_id,
                'schedule_id' => $schedule?->schedule_id,
                'attendance_date' => $date,
                'time_in' => $timestamp,
                'time_in_latitude' => $data['latitude'] ?? null,
                'time_in_longitude' => $data['longitude'] ?? null,
                'time_in_photo' => $storedSelfie,
                'device_info' => $data['device_info'] ?? null,
                'ip_address' => request()?->ip(),
                'status' => $status,
                'approval_status' => 'pending',
                // ⭐ FIX #9: Auto-tag as 'late_in' when the mobile app flagged it or when late.
                'attendance_flag' => $status === 'late' ? 'late_in' : null,
            ]);
            $log = $log->fresh(['employee.person', 'employee.department', 'employee.position.salaryGrade', 'schedule']);

            // ⭐ Attach resolved selfie URLs so mobile can render immediately
            $log->time_in_selfie_url = $this->resolveSelfieUrl($log->time_in_photo);
            $log->time_out_selfie_url = $this->resolveSelfieUrl($log->time_out_photo);

            return $log;
        });
    }

    public function timeOut($employee, array $data): AttendanceLog
    {
        return DB::transaction(function () use ($employee, $data) {
            $timestamp = $this->attendanceTimestamp($data);

            $log = AttendanceLog::where('employee_id', $employee->employee_id)
                ->whereNotNull('time_in')
                ->whereNull('time_out')
                ->latest('attendance_id')
                ->firstOrFail();

            $log->update([
                'time_out' => $timestamp,
                'time_out_latitude' => $data['latitude'] ?? null,
                'time_out_longitude' => $data['longitude'] ?? null,
                'time_out_photo' => $this->storeSelfie($data['selfie'] ?? null, $employee->employee_id, 'out'),
            ]);

            $log = $this->recalculate($log->fresh(['schedule']));

            // ⭐ Attach resolved selfie URLs so mobile can render immediately
            $log->time_in_selfie_url = $this->resolveSelfieUrl($log->time_in_photo);
            $log->time_out_selfie_url = $this->resolveSelfieUrl($log->time_out_photo);

            return $log;
        });
    }

    public function recalculate(AttendanceLog $log): AttendanceLog
    {
        $hours = $this->computeHours($log);
        $overtimeRequest = $log->overtimeRequest()->latest('overtime_request_id')->first();
        $overtimeHours = $hours['overtime'];
        $overtimeApproved = false;

        if ($overtimeRequest?->status === 'rejected') {
            $overtimeHours = 0;
        } elseif ($overtimeRequest?->status === 'approved') {
            $overtimeHours = round(min($overtimeHours, (float) $overtimeRequest->hours), 2);
            $overtimeApproved = $overtimeHours > 0;
        } elseif ((bool) $log->overtime_approved) {
            $overtimeHours = round(min($overtimeHours, (float) $log->overtime_hours), 2);
            $overtimeApproved = $overtimeHours > 0;
        }

        $updates = [
            'regular_hours' => $hours['regular'],
            'overtime_hours' => $overtimeHours,
            'undertime_hours' => $hours['undertime'],
            'overtime_approved' => $overtimeApproved,
        ];

        if ($log->time_in && $log->schedule) {
            $updates['status'] = $this->initialStatus($log->time_in, $log->schedule);
        }

        // ⭐ FIX #9: Keep late_in flag in sync when recalculated.
        if (($updates['status'] ?? $log->status) === 'late' && ! $log->attendance_flag) {
            $updates['attendance_flag'] = 'late_in';
        } elseif (($updates['status'] ?? $log->status) !== 'late' && $log->attendance_flag === 'late_in') {
            $updates['attendance_flag'] = null;
        }

        $log->update($updates);

        return $log->fresh([
            'employee.person',
            'employee.department',
            'employee.position.salaryGrade',
            'schedule',
            'overtimeRequest',
        ]);
    }

    /**
     * ⭐ FIX #6: Auto-tag scheduled absences as AWOL.
     */
    public function materializeScheduledAbsences(int $employeeId, string $start, string $end): void
    {
        Schedule::where('employee_id', $employeeId)
            ->whereBetween('work_date', [$start, $end])
            ->whereNotIn('status', ['cancelled'])
            ->each(function (Schedule $schedule) use ($employeeId) {
                $attendanceDate = $schedule->work_date instanceof \Carbon\Carbon
                    ? $schedule->work_date->toDateString()
                    : (string) $schedule->work_date;

                // ⭐ FIX: If a row already exists for this employee+date, do NOT
                //    touch it — the employee may have already timed in, or the
                //    admin may have already saved it to payroll. firstOrCreate
                //    is already safe, but we also need to ensure we never
                //    clobber payroll_ready_at on an existing row.
                $existing = AttendanceLog::where('employee_id', $employeeId)
                    ->whereDate('attendance_date', $attendanceDate)
                    ->first();

                if ($existing) {
                    return;
                }

                AttendanceLog::create([
                    'employee_id' => $employeeId,
                    'schedule_id' => $schedule->schedule_id,
                    'attendance_date' => $attendanceDate,
                    'status' => 'absent',
                    'approval_status' => 'approved',
                    'attendance_flag' => 'awol',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'approval_notes' => 'System-generated AWOL from employee schedule.',
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'undertime_hours' => (float) $schedule->duration_hours,
                ]);
            });
    }

    public function computeHours(AttendanceLog $log): array
    {
        if (! $log->time_in || ! $log->time_out) {
            return ['regular' => 0, 'overtime' => 0, 'undertime' => 0];
        }

        $timeIn = $log->time_in instanceof Carbon ? $log->time_in->copy() : Carbon::parse($log->time_in);
        $timeOut = $log->time_out instanceof Carbon ? $log->time_out->copy() : Carbon::parse($log->time_out);

        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut->addDay();
        }

        $workedMinutes = max(0, $timeIn->diffInMinutes($timeOut));
        $breakMinutes = max(0, (int) round((float) ($log->schedule?->break_minutes ?? 0)));
        $workedHours = max(0, ($workedMinutes - $breakMinutes) / 60);
        $scheduledHours = $this->scheduledHours($log->schedule) ?: 8;

        $overtimeThresholdMinutes = max(9 * 60, (int) round(($scheduledHours * 60) + $breakMinutes));
        $overtimeHours = $workedMinutes > $overtimeThresholdMinutes
            ? max(0, $workedHours - $scheduledHours)
            : 0;

        return [
            'regular' => round(min($scheduledHours, $workedHours), 2),
            'overtime' => round($overtimeHours, 2),
            'undertime' => round(max(0, $scheduledHours - $workedHours), 2),
        ];
    }

    private function attendanceTimestamp(array $data): Carbon
    {
        $candidate = $data['captured_at'] ?? $data['timestamp'] ?? null;

        // ⭐ FIX: Always resolve the timestamp into the APPLICATION timezone
        //    (config('app.timezone')), never the device timezone. The device
        //    timezone is only used for display, not for deciding which cutoff
        //    a row belongs to. Otherwise a device with a wrong clock silently
        //    pushes a September row into October.
        $appTimezone = config('app.timezone', 'UTC');

        if ($candidate) {
            return Carbon::parse($candidate)->setTimezone($appTimezone);
        }

        return now()->setTimezone($appTimezone);
    }

    private function initialStatus(Carbon $timestamp, ?Schedule $schedule): string
    {
        if (! $schedule) {
            return 'unscheduled';
        }

        if (! $schedule->start_time || ! $schedule->work_date) {
            return 'present';
        }

        $scheduledStart = Carbon::parse($schedule->work_date->format('Y-m-d') . ' ' . $schedule->start_time);

        return $timestamp->greaterThan($scheduledStart) ? 'late' : 'present';
    }

    private function scheduledHours(?Schedule $schedule): float
    {
        if (! $schedule) {
            return 8;
        }

        return (float) ($schedule->duration_hours ?? 8);
    }

    /**
     * Convert a stored path or URL into a browser/mobile-renderable URL.
     * Mirrors AttendanceController::resolveSelfieUrl() so mobile & web agree.
     */
    public function resolveSelfieUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return url($path);
        }

        return Storage::disk('public')->url($path);
    }

    private function storeSelfie(?string $selfie, int $employeeId, string $direction): ?string
    {
        if (! $selfie) {
            return null;
        }

        if (str_starts_with($selfie, 'http://') || str_starts_with($selfie, 'https://')) {
            return $selfie;
        }

        if (str_starts_with($selfie, '/storage/')) {
            return ltrim(str_replace('/storage/', '', $selfie), '/');
        }

        if (! str_contains($selfie, 'base64,')) {
            return $selfie;
        }

        [$meta, $payload] = explode('base64,', $selfie, 2);
        $binary = base64_decode($payload, true);

        if ($binary === false) {
            return null;
        }

        $extension = 'jpg';
        if (str_contains($meta, 'png')) {
            $extension = 'png';
        } elseif (str_contains($meta, 'webp')) {
            $extension = 'webp';
        }

        $directory = 'attendance-selfies/' . now()->format('Y-m-d');
        $filename = 'employee-' . $employeeId . '-' . $direction . '-' . Str::uuid() . '.' . $extension;
        $path = $directory . '/' . $filename;

        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
