<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id('zone_id');
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('coverage_areas')->nullable();
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_km_rate', 10, 2)->default(0);
            $table->decimal('min_order_for_free_delivery', 10, 2)->nullable();
            $table->integer('estimated_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
