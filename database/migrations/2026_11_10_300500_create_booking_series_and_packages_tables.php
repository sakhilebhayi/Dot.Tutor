<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring weekly series (one row per series; each occurrence is a
 * tutor_sessions row pointing back here) and lesson packages bought as a
 * block of credits (each booking made with a credit points at the purchase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_booking_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tutor_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->string('local_time', 5);
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('weeks');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('tutor_package_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tutor_offering_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('lessons_total');
            $table->unsignedSmallInteger('lessons_used')->default(0);
            $table->unsignedSmallInteger('lesson_minutes');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            // awaiting_guardian | awaiting_payment | active | exhausted | expired | cancelled
            $table->string('status', 20);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('order_reference', 120)->nullable();
            $table->string('guardian_status', 20)->default('not_required');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_package_purchases');
        Schema::dropIfExists('tutor_booking_series');
    }
};
