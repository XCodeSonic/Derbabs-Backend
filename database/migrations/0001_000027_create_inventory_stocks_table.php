<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id('stock_id');
            $table->foreignId('ingredient_id')->unique()->constrained('ingredients','ingredient_id')->cascadeOnDelete();
            $table->decimal('current_quantity',12,3)->default(0);
            $table->decimal('reserved_quantity',12,3)->default(0);
            $table->decimal('minimum_quantity',12,3)->default(10);
            $table->decimal('maximum_quantity',12,3)->default(100);
            $table->decimal('max_stock_level',12,3)->nullable();
            $table->decimal('reorder_point',12,3)->default(15);
            $table->string('storage_location')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('last_restocked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
