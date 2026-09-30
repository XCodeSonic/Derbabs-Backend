<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class BookingItem extends Model{protected $table='booking_items'; protected $primaryKey='booking_item_id'; protected $guarded=[];
public function booking(){return $this->belongsTo(Booking::class,'booking_id','booking_id');}
public function menuItem(){return $this->belongsTo(MenuItem::class,'menu_item_id','menu_item_id');}
public function mealService(){return $this->belongsTo(MealService::class,'meal_service_id','meal_service_id');}
}
