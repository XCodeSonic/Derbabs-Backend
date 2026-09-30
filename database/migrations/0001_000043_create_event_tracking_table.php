<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tracking', function (Blueprint $table) {
            $table->id('tracking_id');
            $table->foreignId('booking_id')->constrained('bookings','booking_id')->cascadeOnDelete();
            $table->enum('stage',['preparation','ready','ongoing','completed']);
            $table->integer('progress_percentage')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('stage_started_at')->nullable();
            $table->timestamp('stage_completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamps();
            $table->unique(['booking_id','stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tracking');
    }
};
