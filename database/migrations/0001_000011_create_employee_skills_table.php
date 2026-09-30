<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id('skill_id');
            $table->foreignId('employee_id')->constrained('employees','employee_id')->cascadeOnDelete();
            $table->string('skill_name',100);
            $table->enum('proficiency',['beginner','intermediate','advanced','expert'])->default('intermediate');
            $table->integer('years_experience')->default(0);
            $table->timestamps();
            $table->unique(['employee_id','skill_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
    }
};
