<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualifications as records (not free text), each with private evidence files
 * and a review status, plus an append-only record of every verification
 * decision an operator takes. The old tutor_profiles.qualifications text column
 * stays as history; it is shown as "self-declared" and never as verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title', 200);
            $table->string('institution', 200)->nullable();
            $table->unsignedSmallInteger('year_awarded')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('unverified');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['tutor_profile_id', 'status']);
            $table->index('status');
        });

        Schema::create('tutor_qualification_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_qualification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 80);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
        });

        Schema::create('tutor_verification_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            // 'qualification' (a record verified or rejected) or 'level' (verification level assigned or removed).
            $table->string('subject', 20);
            $table->unsignedBigInteger('tutor_qualification_id')->nullable();
            $table->string('from_value', 40)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->text('reason');
            $table->json('evidence_ids')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tutor_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_verification_decisions');
        Schema::dropIfExists('tutor_qualification_evidence');
        Schema::dropIfExists('tutor_qualifications');
    }
};
