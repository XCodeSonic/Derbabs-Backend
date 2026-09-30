<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            if (!Schema::hasColumn('cart_items', 'pricing_type')) {
                $table->string('pricing_type', 20)
                    ->default('per_pax')
                    ->after('menu_item_id');
            }

            if (!Schema::hasColumn('cart_items', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)
                    ->default(0)
                    ->after('pricing_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'unit_price']);
        });
    }
};
