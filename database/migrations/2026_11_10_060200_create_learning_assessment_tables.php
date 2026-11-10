<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 assessments: question banks per topic, assessments (course quizzes, tutor assessments, practice), assignments,
 * attempts marked on the server, and flashcards with spaced review. Topic mastery is computed from attempt answers
 * on read (no cached score that could drift from the evidence).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->unsignedBigInteger('topic_id')->nullable()->index();
            $table->string('strand')->nullable();
            $table->string('topic');
            $table->string('language', 20)->default('en');
            $table->timestamps();
        });

        Schema::create('learning_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('learning_question_banks')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->json('answer');
            $table->text('explanation')->nullable();
            $table->string('topic')->nullable();
            $table->unsignedSmallInteger('marks')->default(1);
            $table->string('difficulty', 20)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('learning_courses')->nullOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->string('kind', 20); // course_quiz | tutor_assessment | practice
            $table->unsignedTinyInteger('pass_percent')->default(50);
            $table->unsignedInteger('time_limit_minutes')->nullable();
            $table->unsignedInteger('max_attempts')->nullable();
            $table->boolean('shuffle_questions')->default(true);
            $table->string('reveal_answers', 20)->default('after_submit'); // after_submit | never
            $table->timestamps();
        });

        Schema::create('learning_assessment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('learning_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('learning_questions')->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['assessment_id', 'question_id']);
        });

        Schema::create('learning_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('learning_assessments')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'learner_id']);
        });

        Schema::create('learning_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('learning_assessments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('question_order');
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('late')->default(false);
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('max_score')->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'submitted_at']);
        });

        Schema::create('learning_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('learning_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('learning_questions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('strand')->nullable();
            $table->string('topic');
            $table->json('response')->nullable();
            $table->boolean('is_correct');
            $table->unsignedSmallInteger('marks_awarded');
            $table->unsignedSmallInteger('marks_possible');
            $table->boolean('counts_for_mastery')->default(true);
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->index(['user_id', 'topic']);
        });

        Schema::create('learning_flashcard_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('topic')->nullable();
            $table->string('language', 20)->default('en');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });

        Schema::create('learning_flashcards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('learning_flashcard_sets')->cascadeOnDelete();
            $table->text('front');
            $table->text('back');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('learning_flashcard_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flashcard_id')->constrained('learning_flashcards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('repetitions')->default(0);
            $table->unsignedInteger('interval_days')->default(0);
            $table->decimal('ease', 4, 2)->default(2.50);
            $table->timestamp('due_at')->index();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedTinyInteger('last_grade')->nullable();
            $table->timestamps();
            $table->unique(['flashcard_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'learning_flashcard_reviews', 'learning_flashcards', 'learning_flashcard_sets', 'learning_attempt_answers',
            'learning_attempts', 'learning_assignments', 'learning_assessment_questions', 'learning_assessments',
            'learning_questions', 'learning_question_banks',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
