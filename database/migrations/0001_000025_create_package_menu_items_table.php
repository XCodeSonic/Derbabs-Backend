<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_menu_items', function (Blueprint $table) {
            $table->id('package_menu_item_id');
            $table->foreignId('package_id')
                ->constrained('packages', 'package_id')
                ->cascadeOnDelete();
            $table->foreignId('menu_item_id')
                ->constrained('menu_items', 'menu_item_id')
                ->restrictOnDelete();
            $table->integer('quantity_per_pax')->default(1);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_replaceable')->default(false);
            $table->decimal('additional_cost', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['package_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_menu_items');
    }
};
