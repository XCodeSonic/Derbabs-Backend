<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id('package_id');
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price_per_pax', 10, 2);
            $table->decimal('price_per_additional_pax', 10, 2)->default(0);
            $table->integer('min_pax')->default(1);
            $table->integer('max_pax')->default(100);
            $table->integer('default_duration_hours')->default(4);
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
