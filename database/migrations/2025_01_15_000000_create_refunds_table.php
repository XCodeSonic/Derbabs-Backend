<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id('refund_id');
            $table->string('refund_number')->unique();
            
            // Relations
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            
            // Refund details
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('released_amount', 12, 2)->nullable();
            $table->decimal('deposit_snapshot', 12, 2)->default(0);
            
            // Status: pending, approved, released, rejected, cancelled
            $table->string('status', 30)->default('pending');
            
            // Reason & notes
            $table->text('reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Payment details (upon release)
            $table->string('payment_method', 50)->nullable();
            $table->string('reference_number', 100)->nullable();
            
            // Tracking timestamps
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            // Source tracking
            $table->string('source', 30)->default('booking'); // booking, invoice, admin_direct
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('booking_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index('requested_at');
            
            // Foreign keys (optional - depends on your setup)
            $table->foreign('booking_id')->references('booking_id')->on('bookings')->onDelete('set null');
            $table->foreign('invoice_id')->references('invoice_id')->on('invoices')->onDelete('set null');
        });

        // Add refund columns to invoices for quick access
        if (!Schema::hasColumn('invoices', 'refund_status')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('refund_status', 30)->nullable()->after('status');
                $table->decimal('refund_amount', 12, 2)->default(0)->after('refund_status');
                $table->decimal('refund_released', 12, 2)->default(0)->after('refund_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_amount', 'refund_released']);
        });
        
        Schema::dropIfExists('refunds');
    }
};