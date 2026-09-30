<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use SoftDeletes;

    protected $table = 'employees';
    protected $primaryKey = 'employee_id';
    protected $guarded = [];

    protected $casts = [
        'hire_date' => 'date',
        'regularization_date' => 'date',
        'termination_date' => 'date',
        'hourly_rate' => 'float',
    ];

    protected $appends = [
        'id',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'full_name',
        'employee_type',
        'calculated_hourly_rate',
        'profile_photo',
        'profile_photo_url',
        'address',
        'address_line_1',
        'city',
        'state',
        'province',
        'postal_code',
        'country',
        'birth_date',
        'gender',
        'can_login',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id', 'person_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id', 'position_id');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class, 'employee_id', 'employee_id');
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class, 'employee_id', 'employee_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id', 'employee_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'employee_id', 'employee_id');
    }

    public function getIdAttribute()
    {
        return $this->employee_id;
    }

    public function getFirstNameAttribute(): ?string
    {
        return $this->person?->first_name;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->person?->last_name;
    }

    public function getMiddleNameAttribute(): ?string
    {
        return $this->person?->middle_name;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->person?->email;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->person?->phone;
    }

    public function getFullNameAttribute(): string
    {
        return trim(collect([
            $this->person?->first_name,
            $this->person?->middle_name,
            $this->person?->last_name,
            $this->person?->suffix,
        ])->filter()->implode(' '));
    }

    public function getEmployeeTypeAttribute(): string
    {
        return $this->position?->employment_type ?? 'full_time';
    }

    public function getCalculatedHourlyRateAttribute(): float
    {
        return (float) ($this->hourly_rate ?: ($this->position?->salaryGrade?->default_hourly_rate ?? 0));
    }

    public function getProfilePhotoAttribute(): ?string
    {
        return $this->person?->profile_photo;
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        $path = $this->person?->profile_photo;

        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return url(Storage::disk('public')->url($path));
    }

    public function getAddressAttribute(): ?string
    {
        return $this->person?->address_line_1;
    }

    public function getAddressLine1Attribute(): ?string
    {
        return $this->person?->address_line_1;
    }

    public function getCityAttribute(): ?string
    {
        return $this->person?->city;
    }

    public function getStateAttribute(): ?string
    {
        return $this->person?->province;
    }

    public function getProvinceAttribute(): ?string
    {
        return $this->person?->province;
    }

    public function getPostalCodeAttribute(): ?string
    {
        return $this->person?->postal_code;
    }

    public function getCountryAttribute(): ?string
    {
        return $this->person?->country;
    }

    public function getBirthDateAttribute(): ?string
    {
        return $this->person?->birth_date?->toDateString();
    }

    public function getGenderAttribute(): ?string
    {
        return $this->person?->gender;
    }

    /**
     * ⭐ #2 — Whether this employee is allowed to log into Attendance Tracking.
     *
     * Returns true only when BOTH:
     *   - The employee record status is "active" or "on_leave"
     *   - The linked user account (if it exists) is active
     */
    public function getCanLoginAttribute(): bool
    {
        $status = strtolower((string) $this->status) ?: 'active';
        $allowedStatuses = ['active', 'on_leave', 'onleave', 'on-leave'];

        if (! in_array($status, $allowedStatuses, true)) {
            return false;
        }

        if ($this->user && ! $this->user->is_active) {
            return false;
        }

        return true;
    }

    public function isActive(): bool
    {
        $status = strtolower((string) $this->status) ?: 'active';
        return $status === 'active';
    }

    public function isInactive(): bool
    {
        return ! $this->getCanLoginAttribute();
    }
}