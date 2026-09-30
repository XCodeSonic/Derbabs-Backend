<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id('recipe_ingredient_id');
            $table->foreignId('menu_item_id')
                ->constrained('menu_items', 'menu_item_id')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients', 'ingredient_id')
                ->cascadeOnDelete();
            $table->decimal('quantity_per_pax', 10, 3);
            $table->string('unit', 20);
            $table->timestamps();
            $table->unique(['menu_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};
