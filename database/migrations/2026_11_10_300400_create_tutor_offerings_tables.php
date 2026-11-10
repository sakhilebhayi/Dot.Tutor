<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a tutor sells: lessons, groups, consultations, homework help, exam
 * preparation, revision, packages of n lessons and recurring weekly lessons.
 * Prices in integer minor units with an ISO currency. Offerings are archived
 * (is_active = false), never deleted, because bookings point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('format', 20)->default('online');
            $table->string('area', 120)->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('package_lessons')->nullable();
            $table->unsignedSmallInteger('package_valid_days')->nullable();
            $table->unsignedSmallInteger('recurring_weeks')->nullable();
            // Academic level ids (academic agent's table); empty = every level in the tutor's teaching scope.
            $table->json('level_ids')->nullable();
            $table->unsignedSmallInteger('cancellation_notice_hours')->default(24);
            $table->unsignedTinyInteger('late_cancellation_refund_percent')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tutor_profile_id', 'is_active']);
            $table->index(['type', 'is_active']);
        });

        Schema::create('tutor_offering_subject', function (Blueprint $table) {
            $table->foreignId('tutor_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->primary(['tutor_offering_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_offering_subject');
        Schema::dropIfExists('tutor_offerings');
    }
};
