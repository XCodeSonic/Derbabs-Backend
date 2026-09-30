<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id('payroll_item_id');
            $table->foreignId('payroll_id')->constrained('payrolls','payroll_id')->cascadeOnDelete();
            $table->enum('item_type',['earning','deduction']);
            $table->string('item_name',100);
            $table->decimal('amount',12,2);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
