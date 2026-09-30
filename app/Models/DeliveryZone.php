<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class DeliveryZone extends Model{

protected $table='delivery_zones';
protected $primaryKey='zone_id';
protected $guarded=[];

}
