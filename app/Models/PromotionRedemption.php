<?php
    // app/Models/PromotionRedemption.php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class PromotionRedemption extends Model
    {
        protected $table = 'promotion_redemptions';
        protected $primaryKey = 'redemption_id';
        protected $guarded = [];

        protected $casts = [
            'discount_amount' => 'float',
            'original_amount' => 'float',
            'final_amount' => 'float',
        ];

        public function promotion()
        {
            return $this->belongsTo(Promotion::class, 'promotion_id', 'promotion_id');
        }

        public function booking()
        {
            return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
        }

        public function customer()
        {
            return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
        }
    }