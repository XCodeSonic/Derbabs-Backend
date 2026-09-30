<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id('position_id');
            $table->foreignId('department_id')->constrained('departments','department_id')->restrictOnDelete();
            $table->foreignId('salary_grade_id')->constrained('salary_grades','salary_grade_id')->restrictOnDelete();
            $table->string('title',100);
            $table->string('code',30)->unique();
            $table->text('description')->nullable();
            $table->enum('employment_type',['full_time','part_time','contract','intern','temporary'])->default('full_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['department_id','title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
