<?php
// database/seeders/PackageMenuSeeder.php - UPDATED WITH PACKAGES FROM MENU ITEMS

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;
use App\Models\PackageMenuItem;

class PackageMenuSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'package_id' => 1,
                'name' => 'Seafood Feast Package',
                'slug' => 'seafood-feast-package',
                'description' => 'Complete seafood package with Sweet & Sour Fish, Spanish Sardine, Steamed Fish, and Sautéed Shrimp',
                'base_price_per_pax' => 650.00,
                'price_per_additional_pax' => 550.00,
                'min_pax' => 30,
                'max_pax' => 300,
                'default_duration_hours' => 4,
                'inclusions' => ['Full buffet setup', 'Silverware service', 'Basic decor'],
                'exclusions' => ['Venue rental', 'Floral arrangements'],
                'sort_order' => 1,
                'is_active' => true,
                'is_featured' => true,
                'menu_items' => [
                    ['menu_item_id' => 1, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 2, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 3, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 5, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 26, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 35, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 2,
                'name' => 'Chicken Lovers Package',
                'slug' => 'chicken-lovers-package',
                'description' => 'Delicious chicken dishes including Buttered Chicken, Golden Fried Chicken, Sweet Chili Wings, and Roasted Chicken',
                'base_price_per_pax' => 550.00,
                'price_per_additional_pax' => 450.00,
                'min_pax' => 20,
                'max_pax' => 300,
                'default_duration_hours' => 3,
                'inclusions' => ['Buffet setup', 'Rice service', 'Basic decor'],
                'exclusions' => ['Venue rental', 'Special decorations'],
                'sort_order' => 2,
                'is_active' => true,
                'is_featured' => true,
                'menu_items' => [
                    ['menu_item_id' => 6, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 7, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 8, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 10, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 26, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 36, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => true, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 3,
                'name' => 'Pork Special Package',
                'slug' => 'pork-special-package',
                'description' => 'Pork specialties including Lumpia Shanghai, Pork Chop, Humba, Pork Steak, and Binagongan',
                'base_price_per_pax' => 580.00,
                'price_per_additional_pax' => 480.00,
                'min_pax' => 20,
                'max_pax' => 250,
                'default_duration_hours' => 3,
                'inclusions' => ['Full buffet setup', 'Rice service', 'Table setup'],
                'exclusions' => ['Venue rental', 'Special decorations'],
                'sort_order' => 3,
                'is_active' => true,
                'is_featured' => true,
                'menu_items' => [
                    ['menu_item_id' => 11, 'quantity_per_pax' => 3, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 12, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 14, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 15, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 16, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 25, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 37, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 4,
                'name' => 'Beef Gourmet Package',
                'slug' => 'beef-gourmet-package',
                'description' => 'Premium beef dishes including Beef Steak, Roast Beef, Beef with Broccoli, and Beef Stew',
                'base_price_per_pax' => 700.00,
                'price_per_additional_pax' => 600.00,
                'min_pax' => 20,
                'max_pax' => 200,
                'default_duration_hours' => 4,
                'inclusions' => ['Premium buffet setup', 'Silverware service', 'Waitstaff', 'Basic decor'],
                'exclusions' => ['Venue rental', 'Floral arrangements', 'Special decor'],
                'sort_order' => 4,
                'is_active' => true,
                'is_featured' => true,
                'menu_items' => [
                    ['menu_item_id' => 19, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 20, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 22, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 23, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 27, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 38, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => true, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 5,
                'name' => 'Pasta & Noodles Package',
                'slug' => 'pasta-noodles-package',
                'description' => 'Pasta favorites including Spaghetti, Carbonara, Lasagna, Pancit Canton, and Sotanghon',
                'base_price_per_pax' => 500.00,
                'price_per_additional_pax' => 400.00,
                'min_pax' => 15,
                'max_pax' => 250,
                'default_duration_hours' => 3,
                'inclusions' => ['Buffet setup', 'Pasta service', 'Basic table setup'],
                'exclusions' => ['Venue rental', 'Special decorations'],
                'sort_order' => 5,
                'is_active' => true,
                'is_featured' => false,
                'menu_items' => [
                    ['menu_item_id' => 29, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 30, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 32, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 33, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 34, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 35, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 6,
                'name' => 'Vegetarian Delight Package',
                'slug' => 'vegetarian-delight-package',
                'description' => 'Vegetarian favorites including Pinakbet, Chopsuey, Four Season Vegetables, and Buttered Vegetables',
                'base_price_per_pax' => 400.00,
                'price_per_additional_pax' => 350.00,
                'min_pax' => 20,
                'max_pax' => 200,
                'default_duration_hours' => 3,
                'inclusions' => ['Vegetarian buffet setup', 'Rice service', 'Basic table setup'],
                'exclusions' => ['Venue rental', 'Special decorations'],
                'sort_order' => 6,
                'is_active' => true,
                'is_featured' => false,
                'menu_items' => [
                    ['menu_item_id' => 25, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 26, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 27, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 28, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 39, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 7,
                'name' => 'Premium Executive Package',
                'slug' => 'premium-executive-package',
                'description' => 'Premium executive package featuring the best of Beef, Chicken, Seafood, and Pasta',
                'base_price_per_pax' => 850.00,
                'price_per_additional_pax' => 750.00,
                'min_pax' => 30,
                'max_pax' => 300,
                'default_duration_hours' => 5,
                'inclusions' => ['Executive buffet setup', 'Full silverware service', 'Waitstaff', 'Basic decor'],
                'exclusions' => ['Venue rental', 'Floral arrangements'],
                'sort_order' => 7,
                'is_active' => true,
                'is_featured' => true,
                'menu_items' => [
                    ['menu_item_id' => 1, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 8, 'quantity_per_pax' => 2, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 12, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 19, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 22, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 50],
                    ['menu_item_id' => 33, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 26, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 35, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                ],
            ],
            [
                'package_id' => 8,
                'name' => 'Family Reunion Package',
                'slug' => 'family-reunion-package',
                'description' => 'Hearty and home-style package perfect for family reunions and gatherings',
                'base_price_per_pax' => 500.00,
                'price_per_additional_pax' => 400.00,
                'min_pax' => 30,
                'max_pax' => 250,
                'default_duration_hours' => 4,
                'inclusions' => ['Family-style buffet', 'Rice service', 'Basic table setup'],
                'exclusions' => ['Venue rental', 'Decorations'],
                'sort_order' => 8,
                'is_active' => true,
                'is_featured' => false,
                'menu_items' => [
                    ['menu_item_id' => 11, 'quantity_per_pax' => 3, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 6, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 14, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 26, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => false, 'additional_cost' => 0],
                    ['menu_item_id' => 29, 'quantity_per_pax' => 1, 'is_optional' => true, 'is_replaceable' => true, 'additional_cost' => 0],
                    ['menu_item_id' => 36, 'quantity_per_pax' => 1, 'is_optional' => false, 'is_replaceable' => true, 'additional_cost' => 0],
                ],
            ],
        ];

        foreach ($packages as $packageData) {
            $menuItems = $packageData['menu_items'];
            unset($packageData['menu_items']);

            $package = Package::updateOrCreate(
                ['package_id' => $packageData['package_id']],
                $packageData
            );

            // Sync package menu items
            PackageMenuItem::where('package_id', $package->package_id)->delete();
            
            foreach ($menuItems as $item) {
                PackageMenuItem::create([
                    'package_id' => $package->package_id,
                    'menu_item_id' => $item['menu_item_id'],
                    'quantity_per_pax' => $item['quantity_per_pax'],
                    'is_optional' => $item['is_optional'],
                    'is_replaceable' => $item['is_replaceable'],
                    'additional_cost' => $item['additional_cost'],
                ]);
            }
        }
    }
}