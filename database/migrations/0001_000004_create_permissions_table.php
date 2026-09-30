<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id('permission_id');
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->string('module', 50);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
