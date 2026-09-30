<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id('attendance_id');
            $table->foreignId('employee_id')->constrained('employees','employee_id')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('schedules','schedule_id')->nullOnDelete();
            $table->date('attendance_date')->index();
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();
            $table->timestamp('break_start')->nullable();
            $table->timestamp('break_end')->nullable();
            $table->decimal('time_in_latitude',10,8)->nullable();
            $table->decimal('time_in_longitude',11,8)->nullable();
            $table->decimal('time_out_latitude',10,8)->nullable();
            $table->decimal('time_out_longitude',11,8)->nullable();
            $table->string('time_in_photo')->nullable();
            $table->string('time_out_photo')->nullable();
            $table->boolean('face_verified')->default(false);
            $table->string('device_info')->nullable();
            $table->string('ip_address',45)->nullable();
            $table->enum('status',['present','absent','late','half_day','unscheduled'])->default('present');
            $table->decimal('regular_hours',6,2)->default(0);
            $table->decimal('overtime_hours',6,2)->default(0);
            $table->decimal('undertime_hours',6,2)->default(0);
            $table->boolean('overtime_approved')->default(false);
            $table->enum('approval_status',['pending','approved','rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        
        // Add the generated column after the table is created
        DB::statement('ALTER TABLE `attendance_logs` ADD COLUMN `total_hours` DECIMAL(8,2) GENERATED ALWAYS AS (regular_hours + overtime_hours) STORED AFTER `undertime_hours`');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};