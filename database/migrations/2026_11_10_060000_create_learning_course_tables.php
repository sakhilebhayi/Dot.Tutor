<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 learning domain: courses, their structure, published versions, bundles, enrolment, progress, certificates and
 * reviews. Academic references (curriculum, level, topic) are plain indexed ids without foreign keys: those tables
 * belong to the academic domain and are linked by the integration provider (D-601). Money is integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->unsignedBigInteger('curriculum_id')->nullable()->index();
            $table->unsignedBigInteger('level_id')->nullable()->index();
            $table->string('original_locale', 20)->default('en');
            $table->json('objectives')->nullable();
            $table->json('prerequisites')->nullable();
            $table->unsignedInteger('workload_minutes')->nullable();
            $table->string('pricing', 10)->nullable(); // null until the creator chooses: free | paid
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->text('refund_policy')->nullable();
            $table->string('completion_rule', 30)->nullable(); // null = no certificate
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('learning_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('learning_course_sections')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 20);
            $table->longText('body')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('media_original_name')->nullable();
            $table->unsignedBigInteger('assessment_id')->nullable()->index();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('learning_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('learning_lessons')->nullOnDelete();
            $table->string('title');
            $table->string('path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('original_name');
            $table->string('permission', 30)->default('enrolled_download');
            $table->timestamps();
        });

        Schema::create('learning_course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['course_id', 'number']);
        });

        Schema::create('learning_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3)->default('ZAR');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('learning_bundle_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('learning_bundles')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->unique(['bundle_id', 'course_id']);
        });

        Schema::create('learning_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_version_id')->nullable()->constrained('learning_course_versions')->nullOnDelete();
            $table->string('source', 20); // free | entitlement
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('resume_lesson_id')->nullable();
            $table->unsignedInteger('resume_position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
        });

        Schema::create('learning_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->constrained('learning_enrolments')->cascadeOnDelete();
            $table->unsignedBigInteger('lesson_id')->index();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['enrolment_id', 'lesson_id']);
        });

        Schema::create('learning_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->unique()->constrained('learning_enrolments')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('learning_courses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 32)->unique();
            $table->string('learner_name');
            $table->string('course_title');
            $table->string('tutor_name')->nullable();
            $table->unsignedInteger('course_version_number')->nullable();
            $table->boolean('show_full_name')->default(false);
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_course_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('learning_courses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'learning_course_reviews', 'learning_certificates', 'learning_lesson_progress', 'learning_enrolments',
            'learning_bundle_courses', 'learning_bundles', 'learning_course_versions', 'learning_materials',
            'learning_lessons', 'learning_course_sections', 'learning_courses',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
