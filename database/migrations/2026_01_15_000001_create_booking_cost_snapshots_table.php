<?php
// database/migrations/2026_01_15_000001_create_booking_cost_snapshots_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->decimal('ingredient_cost', 15, 2)->default(0);
            $table->decimal('labor_cost', 15, 2)->default(0);
            $table->decimal('delivery_cost', 15, 2)->default(0);
            $table->decimal('equipment_cost', 15, 2)->default(0);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->decimal('food_revenue', 15, 2)->default(0);
            $table->decimal('service_fee', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('extras_revenue', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->json('ingredient_breakdown')->nullable();
            $table->json('menu_breakdown')->nullable();
            $table->string('snapshot_type', 20)->default('projected'); // projected, actual
            $table->timestamp('snapshotted_at')->nullable();
            $table->timestamps();

            $table->foreign('booking_id')->references('booking_id')->on('bookings')->onDelete('cascade');
            $table->index('snapshot_type');
        });

        // Add profitability fields to bookings for quick access
        if (!Schema::hasColumn('bookings', 'profitability_snapshot_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('profitability_snapshot_id')->nullable()->after('booking_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_cost_snapshots');
        if (Schema::hasColumn('bookings', 'profitability_snapshot_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('profitability_snapshot_id');
            });
        }
    }
};