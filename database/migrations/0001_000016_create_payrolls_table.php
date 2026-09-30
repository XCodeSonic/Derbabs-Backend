<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id('payroll_id');
            $table->string('payroll_number',50)->unique();
            $table->foreignId('employee_id')->constrained('employees','employee_id')->restrictOnDelete();
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->date('payment_date')->nullable();
            $table->enum('status',['draft','calculated','approved','paid','cancelled'])->default('draft');
            $table->foreignId('calculated_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id','cutoff_start','cutoff_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
