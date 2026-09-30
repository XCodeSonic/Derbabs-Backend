<?php
// database/seeders/MealCategorySeeder.php - UPDATED

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MealCategory;

class MealCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'category_id' => 1,
                'name' => 'Seafood',
                'slug' => 'seafood',
                'description' => 'Fresh seafood dishes',
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'category_id' => 2,
                'name' => 'Chicken',
                'slug' => 'chicken',
                'description' => 'Premium chicken dishes',
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'category_id' => 3,
                'name' => 'Pork',
                'slug' => 'pork',
                'description' => 'Pork specialties',
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'category_id' => 4,
                'name' => 'Beef',
                'slug' => 'beef',
                'description' => 'Beef dishes',
                'display_order' => 4,
                'is_active' => true,
            ],
            [
                'category_id' => 5,
                'name' => 'Vegetables',
                'slug' => 'vegetables',
                'description' => 'Fresh vegetable dishes',
                'display_order' => 5,
                'is_active' => true,
            ],
            [
                'category_id' => 6,
                'name' => 'Pasta & Noodles',
                'slug' => 'pasta-noodles',
                'description' => 'Pasta and noodle dishes',
                'display_order' => 6,
                'is_active' => true,
            ],
            [
                'category_id' => 7,
                'name' => 'Desserts & Salads',
                'slug' => 'desserts-salads',
                'description' => 'Sweet treats and fresh salads',
                'display_order' => 7,
                'is_active' => true,
            ],
            [
                'category_id' => 8,
                'name' => 'Rice Meals',
                'slug' => 'rice-meals',
                'description' => 'Complete rice meals',
                'display_order' => 8,
                'is_active' => true,
            ],
            [
                'category_id' => 9,
                'name' => 'Snacks & Appetizers',
                'slug' => 'snacks-appetizers',
                'description' => 'Light bites and starters',
                'display_order' => 9,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            MealCategory::updateOrCreate(
                ['category_id' => $category['category_id']],
                $category
            );
        }
    }
}