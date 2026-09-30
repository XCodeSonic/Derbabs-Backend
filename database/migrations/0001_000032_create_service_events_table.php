<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_events', function (Blueprint $table) {
            $table->id('service_event_id');
            $table->foreignId('customer_id')->constrained('customers','customer_id')->restrictOnDelete();
            $table->foreignId('event_type_id')->constrained('event_types','event_type_id')->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages','package_id')->nullOnDelete();
            $table->date('event_date');
            $table->date('event_end_date')->nullable(); // ADD THIS LINE - for multi-day events
            $table->string('event_time',50);
            $table->string('venue',500);
            $table->integer('guests_count')->default(0);
            $table->enum('service_type',['buffet','packed','tray'])->default('buffet');
            $table->enum('menu_selection_type',['package','custom'])->default('package');
            $table->boolean('has_waiters')->default(false);
            $table->enum('delivery_method',['pickup','delivery'])->default('pickup');
            $table->text('special_requests')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('delivery_contact_person',100)->nullable();
            $table->string('delivery_contact_phone',30)->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->datetime('scheduled_delivery_time')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones','zone_id')->nullOnDelete();
            $table->decimal('delivery_fee',10,2)->default(0);
            $table->enum('status',['pending','confirmed','ongoing','completed','cancelled'])->default('pending')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_date','event_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_events');
    }
};