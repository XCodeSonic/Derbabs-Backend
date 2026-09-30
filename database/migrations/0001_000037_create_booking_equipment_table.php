<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_equipment', function (Blueprint $table) {
            $table->id('booking_equipment_id');
            $table->foreignId('booking_id')->constrained('bookings','booking_id')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment','equipment_id')->restrictOnDelete();
            $table->integer('quantity_reserved');
            $table->integer('quantity_used')->default(0);
            $table->integer('quantity_damaged')->default(0);
            $table->integer('quantity_missing')->default(0);
            $table->date('rental_start_date');
            $table->date('rental_end_date');
            $table->decimal('rental_price_at_booking',12,2);
            $table->date('checked_out_date')->nullable();
            $table->date('checked_in_date')->nullable();
            $table->text('condition_notes_out')->nullable();
            $table->text('condition_notes_in')->nullable();
            $table->decimal('damage_charge',12,2)->default(0);
            $table->decimal('missing_charge',12,2)->default(0);
            $table->enum('status',['reserved','checked_out','returned','damaged','missing'])->default('reserved');
            $table->timestamps();
            $table->unique(['booking_id','equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_equipment');
    }
};
