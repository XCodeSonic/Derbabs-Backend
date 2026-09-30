<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id('delivery_id');
            $table->string('delivery_number',50)->unique();
            $table->foreignId('service_event_id')->constrained('service_events','service_event_id')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->datetime('scheduled_pickup')->nullable();
            $table->datetime('scheduled_delivery');
            $table->datetime('actual_pickup')->nullable();
            $table->datetime('actual_delivery')->nullable();
            $table->string('vehicle_type',50)->nullable();
            $table->string('vehicle_plate',20)->nullable();
            $table->enum('status',['pending','assigned','preparing','picked_up','in_transit','arriving','delivered','failed','cancelled'])->default('pending');
            $table->decimal('origin_latitude',10,8)->nullable();
            $table->decimal('origin_longitude',11,8)->nullable();
            $table->decimal('destination_latitude',10,8)->nullable();
            $table->decimal('destination_longitude',11,8)->nullable();
            $table->string('delivery_photo')->nullable();
            $table->string('recipient_signature')->nullable();
            $table->string('received_by',100)->nullable();
            $table->text('recipient_notes')->nullable();
            $table->decimal('additional_charges',10,2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
