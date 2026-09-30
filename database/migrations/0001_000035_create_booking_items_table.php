<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id('booking_item_id');
            $table->foreignId('booking_id')->constrained('bookings','booking_id')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items','menu_item_id')->nullOnDelete();
            $table->string('custom_item_name',200)->nullable();
            $table->text('description')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price',12,2);
            $table->enum('item_type',['menu_item','custom_item','service_fee','add_on'])->default('menu_item');
            $table->enum('action_type',['included','added','removed'])->default('included');
            $table->text('special_instructions')->nullable();
            $table->timestamps();
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
