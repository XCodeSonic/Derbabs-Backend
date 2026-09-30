<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users','user_id')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles','role_id')->cascadeOnDelete();
            $table->primary(['user_id','role_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
