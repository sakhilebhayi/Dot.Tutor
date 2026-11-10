<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 resource library: uploaded study material with metadata, ownership and licence, recorded reviews (the only
 * source of a "reviewed" or "officially sourced" label), reports (copyright complaints, corrections, suggestions) and
 * recommendations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 30)->index();
            $table->string('country_code', 2)->nullable()->index();
            $table->unsignedBigInteger('curriculum_id')->nullable()->index();
            $table->unsignedBigInteger('level_id')->nullable()->index();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->unsignedBigInteger('topic_id')->nullable()->index();
            $table->string('topic')->nullable();
            $table->string('language', 20)->default('en')->index();
            $table->string('difficulty', 20)->nullable();
            $table->string('institution')->nullable();
            $table->string('ownership', 20); // own_work | third_party
            $table->string('licence', 30);
            $table->string('source_url', 2048)->nullable();
            $table->boolean('contributed_as_tutor')->default(false);
            $table->boolean('is_free')->default(true);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->string('status', 20)->default('published')->index();
            $table->string('quality_tier', 30)->default('community')->index();
            $table->string('path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->index();
            $table->string('original_name');
            $table->text('takedown_reason')->nullable();
            $table->timestamp('taken_down_at')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_resource_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('learning_resources')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name');
            $table->string('outcome', 30); // approved | source_confirmed | changes_requested | rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_resource_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('learning_resources')->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20); // copyright | incorrect | improvement | other
            $table->text('details');
            $table->string('claimant_name')->nullable();
            $table->string('work_reference', 2048)->nullable();
            $table->string('status', 20)->default('open')->index(); // open | upheld | dismissed | countered
            $table->text('counter_notice')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('forwarded_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_resource_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('learning_resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['resource_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_resource_recommendations');
        Schema::dropIfExists('learning_resource_reports');
        Schema::dropIfExists('learning_resource_reviews');
        Schema::dropIfExists('learning_resources');
    }
};
