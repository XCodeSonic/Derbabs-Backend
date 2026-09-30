<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class EventType extends Model{

protected $table='event_types';
protected $primaryKey='event_type_id';
protected $guarded=[];

}
