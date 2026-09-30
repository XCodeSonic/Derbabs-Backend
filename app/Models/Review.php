<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class Review extends Model{protected $table='reviews'; protected $primaryKey='review_id'; protected $guarded=[];
public function booking(){return $this->belongsTo(Booking::class,'booking_id','booking_id');}
public function approver(){return $this->belongsTo(User::class,'approved_by','user_id');}
}
