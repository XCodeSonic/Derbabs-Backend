<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryGrade extends Model
{
    use SoftDeletes;

    protected $table = 'salary_grades';
    protected $primaryKey = 'salary_grade_id';
    protected $guarded = [];
    protected $appends = ['id', 'hourly_rate', 'status'];

    public function getIdAttribute()
    {
        return $this->salary_grade_id;
    }

    public function getHourlyRateAttribute()
    {
        return $this->default_hourly_rate;
    }

    public function getStatusAttribute()
    {
        return $this->is_active ? 'active' : 'inactive';
    }
}
