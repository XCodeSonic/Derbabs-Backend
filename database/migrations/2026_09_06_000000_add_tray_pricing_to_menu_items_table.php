// database/migrations/2026_09_06_000000_add_tray_pricing_to_menu_items_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            // Add pricing type column - defaults to 'per_pax'
            if (!Schema::hasColumn('menu_items', 'pricing_type')) {
                $table->enum('pricing_type', ['per_pax', 'per_tray', 'both'])
                    ->default('per_pax')
                    ->after('price')
                    ->comment('per_pax, per_tray, or both');
            }

            // Add tray price column
            if (!Schema::hasColumn('menu_items', 'tray_price')) {
                $table->decimal('tray_price', 10, 2)
                    ->default(0)
                    ->after('pricing_type')
                    ->comment('Price per tray');
            }

            // Add tray servings column
            if (!Schema::hasColumn('menu_items', 'tray_servings')) {
                $table->integer('tray_servings')
                    ->default(25)
                    ->after('tray_price')
                    ->comment('Number of servings per tray');
            }

            // Add tray min pax column
            if (!Schema::hasColumn('menu_items', 'tray_min_pax')) {
                $table->integer('tray_min_pax')
                    ->default(20)
                    ->after('tray_servings')
                    ->comment('Minimum pax for tray option');
            }

            // Add tray max pax column
            if (!Schema::hasColumn('menu_items', 'tray_max_pax')) {
                $table->integer('tray_max_pax')
                    ->default(25)
                    ->after('tray_min_pax')
                    ->comment('Maximum pax for tray option');
            }

            // Add tray description column
            if (!Schema::hasColumn('menu_items', 'tray_description')) {
                $table->string('tray_description', 255)
                    ->nullable()
                    ->after('tray_max_pax')
                    ->comment('Description for tray option e.g. Good for 20-25 pax');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $columns = [
                'pricing_type',
                'tray_price',
                'tray_servings',
                'tray_min_pax',
                'tray_max_pax',
                'tray_description'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('menu_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
