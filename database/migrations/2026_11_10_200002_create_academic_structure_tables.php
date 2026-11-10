<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 academic domain (D-200 block): the academic structure as data.
 *
 * country -> qualification framework -> curriculum -> ordered levels (grouped
 * into seeded stages) -> subject offerings (curriculum_subject_levels) -> topics.
 * The existing `subjects` table becomes the global subject catalogue: every
 * column added to it is nullable, so existing rows, the ids that tutor_subject
 * and tutor_sessions point at, and the legacy `level` string all stay as they
 * are. Legacy level strings are mapped onto levels in
 * `legacy_subject_level_map` by `php artisan academic:sync`.
 *
 * Deletes restrict everywhere a learner, a tutor or history may point: the
 * structure is deactivated, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique(); // ISO 3166-1 alpha-2
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('qualification_frameworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete(); // null: international
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('short_name', 40)->nullable();
            $table->string('authority', 160)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete(); // null: international
            $table->foreignId('qualification_framework_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('short_name', 40)->nullable();
            $table->string('kind', 20); // school | tertiary | professional
            $table->string('authority', 160)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('academic_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('kind', 20); // school | tertiary
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('academic_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('academic_stage_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('short_name', 40)->nullable();
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('nqf_level')->nullable();
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['curriculum_id', 'code']);
            $table->index(['curriculum_id', 'position']);
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique();
            $table->string('family', 60)->nullable()->index();
            $table->string('category', 40)->nullable();
            $table->json('aliases')->nullable();
            $table->foreignId('canonical_subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language_tier', 30)->nullable(); // home | first_additional
            $table->text('description')->nullable();
        });

        Schema::create('curriculum_subject_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('academic_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->boolean('is_compulsory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['academic_level_id', 'subject_id']);
            $table->index(['curriculum_id', 'subject_id']);
            $table->index(['subject_id', 'academic_level_id']);
        });

        Schema::create('academic_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_subject_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('academic_topics')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['curriculum_subject_level_id', 'slug']);
        });

        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('short_name', 40)->nullable();
            $table->string('kind', 40); // university | university_of_technology | tvet_college | private_higher_education | school | other
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'name']);
        });

        Schema::create('legacy_subject_level_map', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_level', 40);
            $table->foreignId('academic_level_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['legacy_level', 'academic_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_subject_level_map');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('academic_topics');
        Schema::dropIfExists('curriculum_subject_levels');

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropForeign(['canonical_subject_id']);
            $table->dropForeign(['language_id']);
            $table->dropUnique(['slug']);
            $table->dropIndex(['family']);
        });
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['slug', 'family', 'category', 'aliases', 'canonical_subject_id', 'language_id', 'language_tier', 'description']);
        });

        Schema::dropIfExists('academic_levels');
        Schema::dropIfExists('academic_stages');
        Schema::dropIfExists('curricula');
        Schema::dropIfExists('qualification_frameworks');
        Schema::dropIfExists('countries');
    }
};
