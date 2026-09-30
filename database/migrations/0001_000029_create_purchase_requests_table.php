<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id('purchase_request_id');
            $table->string('pr_number',50)->unique();
            $table->foreignId('ingredient_id')->constrained('ingredients','ingredient_id')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers','supplier_id')->nullOnDelete();
            $table->decimal('quantity',12,3);
            $table->enum('urgency',['normal','urgent','critical'])->default('normal');
            $table->enum('status',['pending','approved','ordered','received','cancelled'])->default('pending');
            $table->date('expected_delivery')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users','user_id')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
