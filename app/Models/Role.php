<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Role extends Model{

protected $table='roles';
protected $primaryKey='role_id';
protected $guarded=[];
public function users(){return $this->belongsToMany(User::class,'user_roles','role_id','user_id')->withTimestamps();}
public function permissions(){return $this->belongsToMany(Permission::class,'role_permissions','role_id','permission_id')->withTimestamps();}
}
