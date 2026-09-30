<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id('employee_id');
            $table->foreignId('person_id')->unique()->constrained('persons','person_id')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users','user_id')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments','department_id')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions','position_id')->restrictOnDelete();
            $table->string('employee_code',50)->unique();
            $table->date('hire_date');
            $table->date('regularization_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->decimal('hourly_rate',12,2);
            $table->string('sss_number',50)->nullable()->unique();
            $table->string('philhealth_number',50)->nullable()->unique();
            $table->string('pagibig_number',50)->nullable()->unique();
            $table->string('tin_number',50)->nullable()->unique();
            $table->enum('status',['active','on_leave','inactive','terminated'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['department_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
