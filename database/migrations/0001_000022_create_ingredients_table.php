<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id('ingredient_id');
            $table->string('name',100)->unique();
            $table->string('sku',50)->nullable()->unique();
            $table->string('unit',20)->default('kg');
            $table->string('category',50)->nullable();
            $table->enum('ingredient_type',['direct','reusable','estimated'])->default('direct');
            $table->decimal('unit_cost',12,2)->nullable();
            $table->integer('lead_time_days')->default(0);
            $table->integer('yield_percentage')->default(100);
            $table->decimal('reuse_factor',5,2)->default(1.00);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
