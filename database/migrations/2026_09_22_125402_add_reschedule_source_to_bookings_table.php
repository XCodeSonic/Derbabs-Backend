<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'reschedule_source')) {
                $table->string('reschedule_source', 30)
                    ->nullable()
                    ->after('reschedule_proposed_by')
                    ->comment('admin_proposal | customer_initial | customer_counter');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'reschedule_source')) {
                $table->dropColumn('reschedule_source');
            }
        });
    }
};