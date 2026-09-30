<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Person extends Model
{
    use SoftDeletes;

    protected $table = 'persons';
    protected $primaryKey = 'person_id';
    protected $guarded = [];
    protected $appends = ['full_name', 'profile_photo_url'];

    protected $casts = [
        'birth_date' => 'date',
         'allergies' => 'array',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'person_id', 'person_id');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'person_id', 'person_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'person_id', 'person_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' '));
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        $path = $this->profile_photo;

        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return url(Storage::disk('public')->url($path));
    }
}
