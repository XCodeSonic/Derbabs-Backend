<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_types', function (Blueprint $table) {
            $table->id('shift_type_id');
            $table->string('name',50)->unique();
            $table->string('slug',50)->unique();
            $table->time('default_start_time');
            $table->time('default_end_time');
            $table->decimal('break_minutes',5,2)->default(60);
            $table->decimal('night_differential_rate',5,2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_types');
    }
};
