<?php
// database/migrations/xxxx_xx_xx_add_promo_fields_to_promotions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Promotion type - default to existing promo_code behavior
            if (!Schema::hasColumn('promotions', 'promo_type')) {
                $table->enum('promo_type', [
                    'menu_discount',
                    'package_discount',
                    'promo_package',
                    'promo_code',
                    'last_minute',
                    'value_added',
                    'booking_planning',
                    'package_upgrade',
                    'referral_loyalty',
                    'event_specific'
                ])->default('promo_code')->after('description');
            }

            // Slug for SEO-friendly URLs
            if (!Schema::hasColumn('promotions', 'slug')) {
                $table->string('slug', 120)->nullable()->unique()->after('name');
            }

            // Discounted price (for fixed price promotions)
            if (!Schema::hasColumn('promotions', 'discounted_price')) {
                $table->decimal('discounted_price', 10, 2)->nullable()->after('discount_value');
            }

            // Time restrictions
            if (!Schema::hasColumn('promotions', 'start_time')) {
                $table->time('start_time')->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('promotions', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }

            // Conditions
            if (!Schema::hasColumn('promotions', 'min_pax')) {
                $table->integer('min_pax')->nullable()->after('end_time');
            }
            if (!Schema::hasColumn('promotions', 'max_pax')) {
                $table->integer('max_pax')->nullable()->after('min_pax');
            }
            if (!Schema::hasColumn('promotions', 'min_booking_amount')) {
                $table->decimal('min_booking_amount', 12, 2)->nullable()->after('max_pax');
            }
            if (!Schema::hasColumn('promotions', 'max_redemptions')) {
                $table->integer('max_redemptions')->nullable()->after('min_booking_amount');
            }
            if (!Schema::hasColumn('promotions', 'redemption_count')) {
                $table->integer('redemption_count')->default(0)->after('max_redemptions');
            }
            if (!Schema::hasColumn('promotions', 'per_customer_limit')) {
                $table->integer('per_customer_limit')->nullable()->after('redemption_count');
            }
            if (!Schema::hasColumn('promotions', 'allow_stacking')) {
                $table->boolean('allow_stacking')->default(false)->after('per_customer_limit');
            }

            // Applicable items
            if (!Schema::hasColumn('promotions', 'applicable_menu_item_ids')) {
                $table->json('applicable_menu_item_ids')->nullable()->after('allow_stacking');
            }
            if (!Schema::hasColumn('promotions', 'applicable_package_ids')) {
                $table->json('applicable_package_ids')->nullable()->after('applicable_menu_item_ids');
            }
            if (!Schema::hasColumn('promotions', 'applicable_event_type_ids')) {
                $table->json('applicable_event_type_ids')->nullable()->after('applicable_package_ids');
            }
            if (!Schema::hasColumn('promotions', 'applicable_days_of_week')) {
                $table->json('applicable_days_of_week')->nullable()->after('applicable_event_type_ids');
            }

            // Value-added promotion fields
            if (!Schema::hasColumn('promotions', 'free_addons')) {
                $table->json('free_addons')->nullable()->after('applicable_days_of_week');
            }
            if (!Schema::hasColumn('promotions', 'complimentary_items')) {
                $table->text('complimentary_items')->nullable()->after('free_addons');
            }

            // Last-minute specials
            if (!Schema::hasColumn('promotions', 'available_dates')) {
                $table->json('available_dates')->nullable()->after('complimentary_items');
            }
            if (!Schema::hasColumn('promotions', 'days_before_event')) {
                $table->integer('days_before_event')->nullable()->after('available_dates');
            }

            // Promo package fields
            if (!Schema::hasColumn('promotions', 'banner_image_url')) {
                $table->string('banner_image_url')->nullable()->after('days_before_event');
            }
            if (!Schema::hasColumn('promotions', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('banner_image_url');
            }

            // Additional flags
            if (!Schema::hasColumn('promotions', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('promotions', 'is_automatic')) {
                $table->boolean('is_automatic')->default(false)->after('is_featured');
            }

            // Indexes for performance
            $table->index(['promo_type', 'is_active']);
            $table->index(['start_date', 'end_date']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Drop all added columns
            $columns = [
                'promo_type', 'slug', 'discounted_price', 'start_time', 'end_time',
                'min_pax', 'max_pax', 'min_booking_amount', 'max_redemptions',
                'redemption_count', 'per_customer_limit', 'allow_stacking',
                'applicable_menu_item_ids', 'applicable_package_ids',
                'applicable_event_type_ids', 'applicable_days_of_week',
                'free_addons', 'complimentary_items', 'available_dates',
                'days_before_event', 'banner_image_url', 'sort_order',
                'is_featured', 'is_automatic'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('promotions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};