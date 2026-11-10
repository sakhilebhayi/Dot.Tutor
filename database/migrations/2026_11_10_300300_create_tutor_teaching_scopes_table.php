<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a tutor teaches: a subject, optionally narrowed to a curriculum and a
 * level range. curriculum_id / level_min_id / level_max_id point at the
 * academic agent's tables (curricula, levels), which do not exist on this
 * branch yet; they are plain indexed integers here and the integration step
 * adds the foreign keys once both sides are merged (decision D-306).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_teaching_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_id')->nullable()->index();
            $table->unsignedBigInteger('level_min_id')->nullable();
            $table->unsignedBigInteger('level_max_id')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'curriculum_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_teaching_scopes');
    }
};
