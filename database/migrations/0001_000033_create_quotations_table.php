<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id('quotation_id');
            $table->string('quote_no',50)->unique();
            $table->foreignId('service_event_id')->unique()->constrained('service_events','service_event_id')->cascadeOnDelete();
            $table->decimal('total_amount',12,2)->default(0);
            $table->enum('status',['pending','approved','accepted','rejected','expired'])->default('pending');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
