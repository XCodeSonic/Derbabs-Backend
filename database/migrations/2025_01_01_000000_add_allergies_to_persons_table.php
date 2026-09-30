<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('persons', 'allergies')) {
            Schema::table('persons', function (Blueprint $table) {
                // JSON array of allergen slugs, e.g. ["peanuts","shellfish"]
                $table->json('allergies')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('persons', 'allergies')) {
            Schema::table('persons', function (Blueprint $table) {
                $table->dropColumn('allergies');
            });
        }
    }
};