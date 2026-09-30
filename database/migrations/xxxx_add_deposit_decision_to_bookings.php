<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'deposit_decision_status')) {
                $table->string('deposit_decision_status')->nullable()->after('booking_status');
            }
            if (!Schema::hasColumn('bookings', 'deposit_decision_action')) {
                $table->string('deposit_decision_action')->nullable()->after('deposit_decision_status');
            }
            if (!Schema::hasColumn('bookings', 'deposit_decision_notes')) {
                $table->text('deposit_decision_notes')->nullable()->after('deposit_decision_action');
            }
            if (!Schema::hasColumn('bookings', 'deposit_extended_until')) {
                $table->date('deposit_extended_until')->nullable()->after('deposit_decision_notes');
            }
            if (!Schema::hasColumn('bookings', 'deposit_decision_at')) {
                $table->timestamp('deposit_decision_at')->nullable()->after('deposit_extended_until');
            }
            if (!Schema::hasColumn('bookings', 'refund_status')) {
                $table->string('refund_status')->nullable()->after('deposit_decision_at');
            }
            if (!Schema::hasColumn('bookings', 'refund_amount')) {
                $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_status');
            }
            if (!Schema::hasColumn('bookings', 'refund_reason')) {
                $table->text('refund_reason')->nullable()->after('refund_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $cols = [
                'deposit_decision_status',
                'deposit_decision_action',
                'deposit_decision_notes',
                'deposit_extended_until',
                'deposit_decision_at',
                'refund_status',
                'refund_amount',
                'refund_reason',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
