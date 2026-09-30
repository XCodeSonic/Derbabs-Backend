<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id('equipment_id');
            $table->string('name',100);
            $table->string('code',50)->unique();
            $table->string('category',50)->nullable();
            $table->text('description')->nullable();
            $table->integer('total_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->string('location')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers','supplier_id')->nullOnDelete();
            $table->string('model',100)->nullable();
            $table->string('serial_number',100)->nullable();
            $table->enum('condition',['Excellent','Good','Fair','Poor'])->default('Good');
            $table->date('last_maintenance')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
