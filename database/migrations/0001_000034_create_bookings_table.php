<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id('booking_id');
            $table->string('booking_no',50)->unique();
            $table->foreignId('service_event_id')->unique()->constrained('service_events','service_event_id')->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->unique()->constrained('quotations','quotation_id')->nullOnDelete();
            $table->decimal('required_deposit',12,2)->default(0);
            $table->enum('booking_status',['pending_approval','confirmed','rejected','cancelled','rescheduled','reschedule_requested','completed'])->default('pending_approval');
            $table->date('requested_date')->nullable();
            $table->string('requested_time',50)->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
