<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'reschedule_proposed_by')) {
                // 'admin' | 'customer' — who initiated the pending proposal
                $table->string('reschedule_proposed_by', 20)
                    ->nullable()
                    ->after('reschedule_reason');
            }

            if (!Schema::hasColumn('bookings', 'reschedule_status')) {
                // 'pending' | 'accepted' | 'rejected' | 'superseded'
                $table->string('reschedule_status', 20)
                    ->nullable()
                    ->after('reschedule_proposed_by');
            }

            if (!Schema::hasColumn('bookings', 'reschedule_proposed_at')) {
                $table->timestamp('reschedule_proposed_at')
                    ->nullable()
                    ->after('reschedule_status');
            }

            if (!Schema::hasColumn('bookings', 'original_event_date')) {
                $table->date('original_event_date')
                    ->nullable()
                    ->after('reschedule_proposed_at');
            }

            if (!Schema::hasColumn('bookings', 'original_event_time')) {
                $table->string('original_event_time', 50)
                    ->nullable()
                    ->after('original_event_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'reschedule_proposed_by',
                'reschedule_status',
                'reschedule_proposed_at',
                'original_event_date',
                'original_event_time',
            ]);
        });
    }
};
