<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->foreignId('person_id')->unique()->constrained('persons', 'person_id')->cascadeOnDelete();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active');

            // ============================================================
            // LOGIN RATE LIMITING COLUMNS
            // 5 failed attempts → 10-minute lockout
            // NOTE: no ->after() calls — MySQL/MariaDB rejects AFTER in CREATE TABLE.
            // ============================================================
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->unsignedTinyInteger('lock_level')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_failed_login_at')->nullable();
            $table->string('last_failed_login_ip', 45)->nullable();

            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
