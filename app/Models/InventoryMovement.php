<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class InventoryMovement extends Model{protected $table='inventory_movements'; protected $primaryKey='movement_id'; protected $guarded=[];
public function ingredient(){return $this->belongsTo(Ingredient::class,'ingredient_id','ingredient_id');}
public function performedBy(){return $this->belongsTo(User::class,'performed_by','user_id');}
}
