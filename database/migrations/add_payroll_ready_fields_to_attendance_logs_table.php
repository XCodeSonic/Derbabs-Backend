<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'payroll_ready_at')) {
                $table->timestamp('payroll_ready_at')->nullable()->after('approval_notes');
            }
            if (!Schema::hasColumn('attendance_logs', 'payroll_ready_by')) {
                $table->foreignId('payroll_ready_by')
                    ->nullable()
                    ->after('payroll_ready_at')
                    ->constrained('users', 'user_id')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('attendance_logs', 'payroll_ready_notes')) {
                $table->text('payroll_ready_notes')->nullable()->after('payroll_ready_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            foreach (['payroll_ready_notes', 'payroll_ready_by', 'payroll_ready_at'] as $column) {
                if (Schema::hasColumn('attendance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
