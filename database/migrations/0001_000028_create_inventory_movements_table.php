<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id('movement_id');
            $table->foreignId('ingredient_id')->constrained('ingredients', 'ingredient_id')->cascadeOnDelete();
            $table->foreignId('performed_by')->constrained('users', 'user_id')->restrictOnDelete();
            $table->enum('movement_type', ['purchase', 'usage', 'return', 'waste', 'adjustment']);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('quantity_change', 12, 3);
            $table->decimal('quantity_before', 12, 3);
            $table->decimal('quantity_after', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 4)->default(0);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
