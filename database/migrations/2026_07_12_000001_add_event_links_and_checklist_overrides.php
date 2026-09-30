<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                if (! Schema::hasColumn('schedules', 'booking_id')) {
                    $table->foreignId('booking_id')
                        ->nullable()
                        ->after('employee_id')
                        ->constrained('bookings', 'booking_id')
                        ->nullOnDelete();
                    $table->index(['booking_id', 'work_date'], 'schedules_booking_work_date_index');
                }
            });
        }

        if (Schema::hasTable('event_checklist_items')) {
            Schema::table('event_checklist_items', function (Blueprint $table) {
                if (! Schema::hasColumn('event_checklist_items', 'manual_override')) {
                    $table->boolean('manual_override')->default(false)->after('status');
                }
                if (! Schema::hasColumn('event_checklist_items', 'completed_at')) {
                    $table->dateTime('completed_at')->nullable()->after('manual_override');
                }
                if (! Schema::hasColumn('event_checklist_items', 'completed_by')) {
                    $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users', 'user_id')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_checklist_items')) {
            Schema::table('event_checklist_items', function (Blueprint $table) {
                if (Schema::hasColumn('event_checklist_items', 'completed_by')) {
                    $table->dropConstrainedForeignId('completed_by');
                }
                foreach (['completed_at', 'manual_override'] as $column) {
                    if (Schema::hasColumn('event_checklist_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('schedules') && Schema::hasColumn('schedules', 'booking_id')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('schedules_booking_work_date_index');
                $table->dropConstrainedForeignId('booking_id');
            });
        }
    }
};
