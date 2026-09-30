<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use SoftDeletes;

    protected $table = 'positions';
    protected $primaryKey = 'position_id';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['id', 'status'];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function salaryGrade()
    {
        return $this->belongsTo(SalaryGrade::class, 'salary_grade_id', 'salary_grade_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'position_id', 'position_id');
    }

    public function getIdAttribute()
    {
        return $this->position_id;
    }

    public function getStatusAttribute(): string
    {
        return $this->is_active ? 'active' : 'inactive';
    }
}
