<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_categories', function (Blueprint $table) {
            $table->id('category_id');
            $table->string('name', 50)->unique();
            $table->string('slug', 60)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_categories');
    }
};
