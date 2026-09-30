<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            // Add missing columns if they don't exist
            if (!Schema::hasColumn('booking_payments', 'account_name')) {
                $table->string('account_name', 100)->nullable()->after('reference_number');
            }
            if (!Schema::hasColumn('booking_payments', 'account_number')) {
                $table->string('account_number', 50)->nullable()->after('account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->dropColumn(['account_name', 'account_number']);
        });
    }
};