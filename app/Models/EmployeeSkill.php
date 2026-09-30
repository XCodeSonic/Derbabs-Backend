<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class EmployeeSkill extends Model{

protected $table='employee_skills';
protected $primaryKey='skill_id';
protected $guarded=[];

}
