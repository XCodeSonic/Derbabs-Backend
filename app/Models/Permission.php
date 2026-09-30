<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Permission extends Model{

protected $table='permissions';
protected $primaryKey='permission_id';
protected $guarded=[];
public function roles(){return $this->belongsToMany(Role::class,'role_permissions','permission_id','role_id')->withTimestamps();}
}
