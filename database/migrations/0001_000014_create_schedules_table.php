<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id('schedule_id');
            $table->foreignId('employee_id')->constrained('employees','employee_id')->cascadeOnDelete();
            $table->foreignId('shift_type_id')->constrained('shift_types','shift_type_id')->restrictOnDelete();
            $table->date('work_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('break_minutes',5,2)->default(0);
            $table->text('assignment_details')->nullable();
            $table->enum('status',['scheduled','in_progress','completed','absent','cancelled'])->default('scheduled');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id','work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
