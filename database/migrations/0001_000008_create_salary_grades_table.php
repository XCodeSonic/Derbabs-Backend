<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_grades', function (Blueprint $table) {
            $table->id('salary_grade_id');
            $table->string('grade_name', 50)->unique();
            $table->string('grade_code', 20)->unique();
            $table->decimal('min_hourly_rate', 12, 2);
            $table->decimal('max_hourly_rate', 12, 2);
            $table->decimal('default_hourly_rate', 12, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_grades');
    }
};
