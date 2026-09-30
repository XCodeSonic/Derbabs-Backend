<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_checklist_items')) {
            Schema::create('event_checklist_items', function (Blueprint $table) {
                $table->id('event_checklist_item_id');
                $table->foreignId('booking_id')->constrained('bookings', 'booking_id')->cascadeOnDelete();
                $table->foreignId('meal_service_id')->nullable()->constrained('meal_services', 'meal_service_id')->nullOnDelete();
                $table->string('task_key', 120);
                $table->string('task', 255);
                $table->string('assigned_to', 120)->nullable();
                $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
                $table->string('source_type', 60)->default('system');
                $table->dateTime('due_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['booking_id', 'task_key']);
                $table->index(['booking_id', 'status']);
                $table->index(['booking_id', 'meal_service_id']);
            });
        }

        if (!Schema::hasTable('event_delivery_trackings')) {
            Schema::create('event_delivery_trackings', function (Blueprint $table) {
                $table->id('event_delivery_tracking_id');
                $table->foreignId('booking_id')->constrained('bookings', 'booking_id')->cascadeOnDelete();
                $table->foreignId('meal_service_id')->nullable()->constrained('meal_services', 'meal_service_id')->nullOnDelete();
                $table->string('delivery_type', 50)->default('food'); // packlunch, buffet, food_tray, food
                $table->date('delivery_date')->nullable();
                $table->string('delivery_time', 50)->nullable();
                $table->string('return_time', 50)->nullable();
                $table->string('venue', 255)->nullable();
                $table->string('driver', 120)->nullable();
                $table->string('driver_phone', 40)->nullable();
                $table->enum('status', ['pending', 'preparing', 'departed', 'en_route', 'arrived', 'serving', 'completed', 'cancelled'])->default('pending');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['booking_id', 'status']);
                $table->index(['meal_service_id', 'delivery_date']);
            });
        }

        if (Schema::hasTable('booking_equipment')) {
            Schema::table('booking_equipment', function (Blueprint $table) {
                if (!Schema::hasColumn('booking_equipment', 'is_out_approved')) {
                    $table->boolean('is_out_approved')->default(false)->after('status');
                }
                if (!Schema::hasColumn('booking_equipment', 'out_approved_at')) {
                    $table->dateTime('out_approved_at')->nullable()->after('is_out_approved');
                }
                if (!Schema::hasColumn('booking_equipment', 'checked_out_by')) {
                    $table->string('checked_out_by', 120)->nullable()->after('checked_out_date');
                }
                if (!Schema::hasColumn('booking_equipment', 'returned_by')) {
                    $table->string('returned_by', 120)->nullable()->after('checked_in_date');
                }
                if (!Schema::hasColumn('booking_equipment', 'return_notes')) {
                    $table->text('return_notes')->nullable()->after('condition_notes_in');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_equipment')) {
            Schema::table('booking_equipment', function (Blueprint $table) {
                foreach (['return_notes', 'returned_by', 'checked_out_by', 'out_approved_at', 'is_out_approved'] as $column) {
                    if (Schema::hasColumn('booking_equipment', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        Schema::dropIfExists('event_delivery_trackings');
        Schema::dropIfExists('event_checklist_items');
    }
};
