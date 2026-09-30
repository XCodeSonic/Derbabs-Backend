<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Promotion;

class PromoMenuSeeder extends Seeder
{
    public function run(): void
    {
        $promotions = [
            [
                'promotion_id' => 1,
                'name' => 'Early Bird Wedding Promo',
                'code' => 'WEDDING2025',
                'description' => 'Get 15% off on wedding packages booked 6 months in advance',
                'discount_type' => 'percentage',
                'discount_value' => 15.00,
                'start_date' => now()->addDays(30)->toDateString(),
                'end_date' => now()->addDays(210)->toDateString(),
                'is_active' => true,
            ],
            [
                'promotion_id' => 2,
                'name' => 'Corporate Bulk Discount',
                'code' => 'CORP2025',
                'description' => 'Discount of ₱5000 for corporate events with 100+ guests',
                'discount_type' => 'fixed',
                'discount_value' => 5000.00,
                'start_date' => now()->addDays(15)->toDateString(),
                'end_date' => now()->addDays(120)->toDateString(),
                'is_active' => true,
            ],
            [
                'promotion_id' => 3,
                'name' => 'Summer Party Special',
                'code' => 'SUMMER25',
                'description' => '10% off all catering bookings for summer events (June-August)',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'start_date' => now()->addDays(60)->toDateString(),
                'end_date' => now()->addDays(150)->toDateString(),
                'is_active' => true,
            ],
            [
                'promotion_id' => 4,
                'name' => 'Holiday Season Package',
                'code' => 'HOLIDAY25',
                'description' => 'Free dessert upgrade for all holiday bookings in December',
                'discount_type' => 'fixed',
                'discount_value' => 1500.00,
                'start_date' => now()->addDays(180)->toDateString(),
                'end_date' => now()->addDays(240)->toDateString(),
                'is_active' => true,
            ],
        ];

        foreach ($promotions as $promotion) {
            Promotion::updateOrCreate(
                ['promotion_id' => $promotion['promotion_id']],
                $promotion
            );
        }
    }
}