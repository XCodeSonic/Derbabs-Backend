<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('pending_approval','confirmed','rejected','cancelled','rescheduled','reschedule_requested','completed','ongoing') DEFAULT 'pending_approval'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('pending_approval','confirmed','rejected','cancelled','rescheduled','reschedule_requested','completed') DEFAULT 'pending_approval'");
    }
};