<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_records', function (Blueprint $table) {
            $table->id('waste_record_id');
            $table->foreignId('ingredient_id')->constrained('ingredients','ingredient_id')->cascadeOnDelete();
            $table->decimal('quantity',12,3);
            $table->enum('reason',['spoilage','expired','damage','prep_waste','other']);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users','user_id')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_records');
    }
};
