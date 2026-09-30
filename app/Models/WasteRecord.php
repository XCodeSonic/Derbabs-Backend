<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WasteRecord extends Model{
protected $table='waste_records';
protected $primaryKey='waste_record_id';
protected $guarded=[];
public function ingredient(){return $this->belongsTo(Ingredient::class,'ingredient_id','ingredient_id');}
public function recorder(){return $this->belongsTo(User::class,'recorded_by','user_id');}
}
