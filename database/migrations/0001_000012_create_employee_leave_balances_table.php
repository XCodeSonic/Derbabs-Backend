<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_balances', function (Blueprint $table) {
            $table->id('balance_id');
            $table->foreignId('employee_id')->constrained('employees','employee_id')->cascadeOnDelete();
            $table->enum('leave_type',['vacation','sick','emergency','maternity','paternity','bereavement']);
            $table->decimal('total_days',5,1)->default(0);
            $table->decimal('used_days',5,1)->default(0);
            $table->year('year');
            $table->timestamps();
            $table->unique(['employee_id','leave_type','year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_balances');
    }
};
