<?php

// database/migrations/xxxx_add_cutoff_columns_to_attendance_logs.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->date('cutoff_start')->nullable()->after('attendance_date');
            $table->date('cutoff_end')->nullable()->after('cutoff_start');
            $table->index(['employee_id', 'cutoff_start', 'cutoff_end'], 'idx_attendance_cutoff');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_cutoff');
            $table->dropColumn(['cutoff_start', 'cutoff_end']);
        });
    }
};
