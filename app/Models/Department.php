<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes;

    protected $table = 'departments';
    protected $primaryKey = 'department_id';
    protected $guarded = [];
    protected $appends = ['id', 'status'];

    public function getIdAttribute()
    {
        return $this->department_id;
    }

    public function getStatusAttribute()
    {
        return $this->is_active ? 'active' : 'inactive';
    }

    public function positions()
    {
        return $this->hasMany(Position::class, 'department_id', 'department_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'department_id', 'department_id');
    }
}
