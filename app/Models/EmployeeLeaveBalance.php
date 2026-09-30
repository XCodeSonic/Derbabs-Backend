<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class EmployeeLeaveBalance extends Model{

protected $table='employee_leave_balances';
protected $primaryKey='balance_id';
protected $guarded=[];

}
