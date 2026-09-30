<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ⭐ Fix #4 — Shift Swap From/To times + From/To employees
        // NOTE: We do NOT use ->after() because the referenced columns
        // (swap_shift_time, etc.) may not exist on this database.
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'swap_from_time')) {
                $table->string('swap_from_time', 20)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'swap_to_time')) {
                $table->string('swap_to_time', 20)->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'swap_from_employee_id')) {
                $table->unsignedBigInteger('swap_from_employee_id')->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'swap_to_employee_id')) {
                $table->unsignedBigInteger('swap_to_employee_id')->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'swap_from_employee_name')) {
                $table->string('swap_from_employee_name')->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'swap_to_employee_name')) {
                $table->string('swap_to_employee_name')->nullable();
            }
        });

        // ⭐ Fix #6, #7, #9 — Attendance flags (AWOL / EA / Leave / Late In)
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'attendance_flag')) {
                $table->string('attendance_flag', 30)->nullable();
            }
            if (!Schema::hasColumn('attendance_logs', 'flagged_by')) {
                $table->unsignedBigInteger('flagged_by')->nullable();
            }
            if (!Schema::hasColumn('attendance_logs', 'flagged_at')) {
                $table->timestamp('flagged_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_logs', 'flag_notes')) {
                $table->text('flag_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $columns = [
                'swap_from_time', 'swap_to_time',
                'swap_from_employee_id', 'swap_to_employee_id',
                'swap_from_employee_name', 'swap_to_employee_name',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('leave_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $columns = ['attendance_flag', 'flagged_by', 'flagged_at', 'flag_notes'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('attendance_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};