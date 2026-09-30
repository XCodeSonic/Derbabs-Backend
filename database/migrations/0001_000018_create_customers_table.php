<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id('customer_id');
            $table->foreignId('person_id')->unique()->constrained('persons','person_id')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users','user_id')->nullOnDelete();
            $table->string('customer_code',50)->unique();
            $table->integer('loyalty_points')->default(0);
            $table->enum('tier',['bronze','silver','gold','platinum'])->default('bronze');
            $table->date('tier_valid_until')->nullable();
            $table->text('dietary_restrictions')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
