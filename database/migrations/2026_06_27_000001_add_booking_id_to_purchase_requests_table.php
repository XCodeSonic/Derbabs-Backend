<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'booking_id')) {
                $table->foreignId('booking_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('bookings', 'booking_id')
                    ->nullOnDelete();
                $table->index(['booking_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'booking_id')) {
                $table->dropConstrainedForeignId('booking_id');
            }
        });
    }
};
