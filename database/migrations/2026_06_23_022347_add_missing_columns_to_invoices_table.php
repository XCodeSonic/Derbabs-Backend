<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'discount_type')) {
                $table->enum('discount_type', ['fixed', 'percentage'])->default('fixed')->after('discount');
            }
            if (!Schema::hasColumn('invoices', 'additional_charges')) {
                $table->decimal('additional_charges', 12, 2)->default(0)->after('discount_type');
            }
            if (!Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'additional_charges', 'notes']);
        });
    }
};