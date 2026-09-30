<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id('cart_item_id');
            $table->foreignId('cart_id')->constrained('carts','cart_id')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items','menu_item_id')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->unique(['cart_id','menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
