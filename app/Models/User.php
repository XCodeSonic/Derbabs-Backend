<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    protected $guarded = [];
    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'is_banned' => 'boolean',
        'banned_at' => 'datetime',
        'failed_login_attempts' => 'integer',
        'lock_level' => 'integer',
        'locked_until' => 'datetime',
        'last_failed_login_at' => 'datetime',
    ];

    // ============================================================
    // LOGIN LOCKOUT CONSTANTS
    // ============================================================
    public const ATTEMPTS_PER_CYCLE = 5;
    public const FIRST_LOCKOUT_MINUTES = 10;
    public const SECOND_LOCKOUT_MINUTES = 60;

    // ============================================================
    // RELATIONSHIPS
    // ============================================================
    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id', 'person_id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withTimestamps();
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id', 'user_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'user_id', 'user_id');
    }

    public function bannedBy()
    {
        return $this->belongsTo(User::class, 'banned_by', 'user_id');
    }

    // ============================================================
    // ROLE HELPERS
    // ============================================================
    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function hasAnyRole(array $slugs): bool
    {
        return $this->roles()->whereIn('slug', $slugs)->exists();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('slug', $slug))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()
            ->where('is_active', true)
            ->whereIn('slug', ['super-admin', 'super_admin', 'superadmin'])
            ->exists();
    }

    // ============================================================
    // LOGIN LOCKOUT HELPERS
    // ============================================================
    public function isLoginLocked(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        if ($this->locked_until->isPast()) {
            $this->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
            return false;
        }

        return true;
    }

    public function lockoutSecondsRemaining(): int
    {
        if (!$this->locked_until || $this->locked_until->isPast()) {
            return 0;
        }
        return now()->diffInSeconds($this->locked_until);
    }

    public function registerFailedLogin(string $ip): array
    {
        if ($this->locked_until && $this->locked_until->isPast()) {
            $this->failed_login_attempts = 0;
            $this->locked_until = null;
        }

        $this->failed_login_attempts = ($this->failed_login_attempts ?? 0) + 1;
        $this->last_failed_login_at = now();
        $this->last_failed_login_ip = $ip;

        $locked = false;
        $lockoutMinutes = 0;

        if ($this->failed_login_attempts >= self::ATTEMPTS_PER_CYCLE) {
            $newLevel = min(2, ($this->lock_level ?? 0) + 1);
            $this->lock_level = $newLevel;

            $lockoutMinutes = $newLevel >= 2
                ? self::SECOND_LOCKOUT_MINUTES
                : self::FIRST_LOCKOUT_MINUTES;

            $this->locked_until = now()->addMinutes($lockoutMinutes);
            $locked = true;
        }

        $this->save();

        return [
            'attempts' => $this->failed_login_attempts,
            'attempts_left' => max(0, self::ATTEMPTS_PER_CYCLE - $this->failed_login_attempts),
            'locked' => $locked,
            'locked_until' => $locked ? $this->locked_until->toIso8601String() : null,
            'seconds_left' => $locked ? now()->diffInSeconds($this->locked_until) : 0,
            'lock_level' => $this->lock_level ?? 0,
            'lockout_minutes' => $lockoutMinutes,
        ];
    }

    public function clearFailedLogins(): void
    {
        if (
            ($this->failed_login_attempts ?? 0) === 0
            && $this->locked_until === null
            && ($this->lock_level ?? 0) === 0
        ) {
            return;
        }

        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'lock_level' => 0,
        ]);
    }
}
