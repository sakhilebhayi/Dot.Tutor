<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 academic domain (D-200 block): learner profiles and translation provenance.
 *
 * A learner profile holds only what matching and age banding need. Date of
 * birth is minimised to the month (`birth_month`, always the 1st): the age band
 * is computed as if the person was born on the LAST day of that month, so a
 * learner is never treated as older than they are. No school name is stored
 * for anyone; an institution is asked for at tertiary levels only.
 *
 * Translations keep the original in the owning model's own column and store
 * translated fields here, with provenance (author, human, machine), the
 * translator, the model when machine, review status, and a hash of the source
 * text so a translation of an edited original shows as out of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->restrictOnDelete();
            $table->foreignId('academic_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->date('birth_month')->nullable();
            $table->boolean('bilingual_ok')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();

            $table->index(['curriculum_id', 'academic_level_id']);
        });

        Schema::create('learner_profile_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_subject_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['learner_profile_id', 'subject_id']);
            $table->index('subject_id');
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translatable_type', 60);
            $table->unsignedBigInteger('translatable_id');
            $table->string('field', 60);
            $table->string('locale', 16);
            $table->longText('body');
            $table->string('provenance', 10); // author | human | machine
            $table->foreignId('translator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('translator_label', 120)->nullable();
            $table->string('machine_model', 120)->nullable();
            $table->string('review_status', 20)->default('unreviewed');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'field', 'locale'], 'translations_target_unique');
            $table->index(['translatable_type', 'translatable_id']);
        });
    }

    public function down(): void
    {
        // Reference rows point at each other with RESTRICT; the rollback drops them together.
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::dropIfExists('translations');
            Schema::dropIfExists('learner_profile_subjects');
            Schema::dropIfExists('learner_profiles');
        });
    }
};
