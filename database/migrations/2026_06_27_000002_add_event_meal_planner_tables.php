<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing service_events table as the parent event record.
        // Only add the scope flag here; money, filters and custom menu rows are kept in child tables.
        if (Schema::hasTable('service_events')) {
            Schema::table('service_events', function (Blueprint $table) {
                if (!Schema::hasColumn('service_events', 'booking_scope')) {
                    $table->string('booking_scope', 30)->default('regular')->after('event_end_date');
                }
            });
        }

        if (!Schema::hasTable('event_days')) {
            Schema::create('event_days', function (Blueprint $table) {
                $table->id('event_day_id');
                $table->foreignId('booking_id')->constrained('bookings', 'booking_id')->cascadeOnDelete();
                $table->unsignedInteger('day_number')->default(1);
                $table->date('date');
                $table->string('day_status', 30)->default('pending');
                $table->timestamps();

                $table->unique(['booking_id', 'day_number']);
                $table->unique(['booking_id', 'date']);
                $table->index(['booking_id', 'date']);
            });
        }

        if (!Schema::hasTable('meal_services')) {
            Schema::create('meal_services', function (Blueprint $table) {
                $table->id('meal_service_id');
                $table->foreignId('booking_id')->constrained('bookings', 'booking_id')->cascadeOnDelete();
                $table->foreignId('event_day_id')->constrained('event_days', 'event_day_id')->cascadeOnDelete();
                $table->string('meal_type', 50); // Breakfast, AM Snacks, Lunch, PM Snacks, Dinner
                $table->string('serving_time', 50)->nullable();
                $table->string('preparation_time', 50)->nullable();
                $table->string('dispatch_time', 50)->nullable();
                $table->string('arrival_time', 50)->nullable();
                $table->unsignedInteger('pax')->default(0);
                $table->enum('menu_source', ['package', 'custom'])->default('custom');
                $table->foreignId('package_id')->nullable()->constrained('packages', 'package_id')->nullOnDelete();
                $table->foreignId('menu_item_id')->nullable()->constrained('menu_items', 'menu_item_id')->nullOnDelete();
                $table->decimal('price_per_head', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->string('assigned_staff', 255)->nullable();
                $table->string('food_quantity', 255)->nullable();
                $table->string('delivery_setup_time', 50)->nullable();
                $table->string('preparation_status', 40)->default('pending');
                $table->string('delivery_status', 40)->default('pending');
                $table->string('serving_status', 40)->default('pending');
                $table->string('meal_status', 40)->default('pending');
                $table->timestamps();

                $table->index(['booking_id', 'event_day_id']);
                $table->index(['booking_id', 'meal_status']);
                $table->index(['event_day_id', 'serving_time']);
            });
        }

        if (!Schema::hasTable('meal_service_filters')) {
            Schema::create('meal_service_filters', function (Blueprint $table) {
                $table->id('meal_service_filter_id');
                $table->foreignId('meal_service_id')->constrained('meal_services', 'meal_service_id')->cascadeOnDelete();
                $table->string('filter_key', 80); // no_pork, chicken, beef, seafood, vegetarian, halal, etc.
                $table->string('filter_value', 120)->nullable();
                $table->timestamps();

                $table->unique(['meal_service_id', 'filter_key']);
                $table->index('filter_key');
            });
        }

        if (!Schema::hasTable('meal_service_custom_items')) {
            Schema::create('meal_service_custom_items', function (Blueprint $table) {
                $table->id('meal_service_custom_item_id');
                $table->foreignId('meal_service_id')->constrained('meal_services', 'meal_service_id')->cascadeOnDelete();
                $table->foreignId('menu_item_id')->nullable()->constrained('menu_items', 'menu_item_id')->nullOnDelete();
                $table->string('item_name', 200)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['meal_service_id', 'menu_item_id']);
            });
        }

        if (!Schema::hasTable('booking_charges')) {
            Schema::create('booking_charges', function (Blueprint $table) {
                $table->id('booking_charge_id');
                $table->foreignId('booking_id')->constrained('bookings', 'booking_id')->cascadeOnDelete();
                $table->enum('charge_kind', ['charge', 'discount'])->default('charge');
                $table->string('charge_type', 80); // transportation_fee, setup_fee, service_crew_fee, discount, etc.
                $table->string('description', 255)->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->timestamps();

                $table->index(['booking_id', 'charge_kind']);
                $table->index('charge_type');
            });
        }

        if (Schema::hasTable('booking_items')) {
            Schema::table('booking_items', function (Blueprint $table) {
                if (!Schema::hasColumn('booking_items', 'meal_service_id')) {
                    $table->unsignedBigInteger('meal_service_id')->nullable()->after('booking_id');
                    $table->foreign('meal_service_id')->references('meal_service_id')->on('meal_services')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('order_items', 'meal_service_id')) {
                    $table->unsignedBigInteger('meal_service_id')->nullable()->after('order_id');
                    $table->foreign('meal_service_id')->references('meal_service_id')->on('meal_services')->nullOnDelete();
                }
                if (!Schema::hasColumn('order_items', 'special_notes')) {
                    $table->text('special_notes')->nullable()->after('unit_price_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'meal_service_id')) {
                    $table->dropForeign(['meal_service_id']);
                    $table->dropColumn('meal_service_id');
                }
                if (Schema::hasColumn('order_items', 'special_notes')) {
                    $table->dropColumn('special_notes');
                }
            });
        }

        if (Schema::hasTable('booking_items')) {
            Schema::table('booking_items', function (Blueprint $table) {
                if (Schema::hasColumn('booking_items', 'meal_service_id')) {
                    $table->dropForeign(['meal_service_id']);
                    $table->dropColumn('meal_service_id');
                }
            });
        }

        Schema::dropIfExists('booking_charges');
        Schema::dropIfExists('meal_service_custom_items');
        Schema::dropIfExists('meal_service_filters');
        Schema::dropIfExists('meal_services');
        Schema::dropIfExists('event_days');

        if (Schema::hasTable('service_events') && Schema::hasColumn('service_events', 'booking_scope')) {
            Schema::table('service_events', function (Blueprint $table) {
                $table->dropColumn('booking_scope');
            });
        }
    }
};
