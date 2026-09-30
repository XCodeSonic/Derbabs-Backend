<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id('menu_item_id');
            $table->foreignId('category_id')
                ->constrained('meal_categories', 'category_id')
                ->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost_to_make', 10, 2)->default(0);
            $table->integer('prep_time_minutes')->default(0);
            $table->integer('serving_size')->default(1);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_vegetarian')->default(false);
            $table->boolean('is_vegan')->default(false);
            $table->boolean('is_gluten_free')->default(false);
            $table->boolean('is_halal')->default(false);
            $table->text('allergens')->nullable();
            $table->text('nutritional_info')->nullable();
            $table->text('ingredients_list')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
