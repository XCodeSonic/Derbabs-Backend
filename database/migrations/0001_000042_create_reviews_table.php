<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id('review_id');
            $table->foreignId('booking_id')->unique()->constrained('bookings','booking_id')->cascadeOnDelete();
            $table->tinyInteger('food_rating')->unsigned();
            $table->tinyInteger('service_rating')->unsigned();
            $table->tinyInteger('value_rating')->unsigned();
            $table->tinyInteger('overall_rating')->unsigned();
            $table->text('comment')->nullable();
            $table->string('photo_1')->nullable();
            $table->string('photo_2')->nullable();
            $table->string('photo_3')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->text('admin_response')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users','user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
