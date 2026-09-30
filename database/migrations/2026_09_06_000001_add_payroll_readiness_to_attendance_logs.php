<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->timestamp('payroll_ready_at')->nullable()->after('approval_notes');
            $table->foreignId('payroll_ready_by')->nullable()->after('payroll_ready_at')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->text('payroll_ready_notes')->nullable()->after('payroll_ready_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['payroll_ready_by']);
            $table->dropColumn(['payroll_ready_at', 'payroll_ready_by', 'payroll_ready_notes']);
        });
    }
};
