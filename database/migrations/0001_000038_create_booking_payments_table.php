<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->string('payment_number',50)->unique();
            $table->foreignId('booking_id')->constrained('bookings','booking_id')->cascadeOnDelete();
            $table->decimal('amount',12,2);
            $table->enum('payment_method',['cash','gcash','maya','bank_transfer','card','check']);
            $table->enum('payment_type',['deposit','partial','full','refund']);
            $table->string('reference_number',100)->nullable();
            $table->string('transaction_id',100)->nullable();
            $table->enum('status',['pending','completed','failed','refunded'])->default('pending');
            $table->string('receipt_file')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['booking_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
