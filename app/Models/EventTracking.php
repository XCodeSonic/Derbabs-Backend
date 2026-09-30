<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class EventTracking extends Model{

protected $table='event_tracking';
protected $primaryKey='tracking_id';
protected $guarded=[];

}
