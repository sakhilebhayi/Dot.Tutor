<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutor workspace (tutor UI agent, D-1200 onward): what the tutor's own business tools need and no wave-1 domain
 * stores. Onboarding answers, private notes about a student, the tutor's referral link and the visits it brought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teach_onboarding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->unique()->constrained('tutor_profiles')->cascadeOnDelete();
            // Whether the tutor wants to teach learners under 18 (drives the safeguarding step; the gate decides).
            $table->boolean('teaches_minors')->default(false);
            $table->timestamp('submitted_at')->nullable();
            // What the tutor changed when sending again after a decision (shown to the reviewing operator).
            $table->text('resubmission_note')->nullable();
            $table->timestamps();
        });

        Schema::create('teach_student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained('tutor_profiles')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->unique(['tutor_profile_id', 'learner_id']);
        });

        Schema::create('teach_referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->unique()->constrained('tutor_profiles')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->timestamps();
        });

        Schema::create('teach_referral_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teach_referral_link_id')->constrained('teach_referral_links')->cascadeOnDelete();
            // Signed-in visitor (the storefront needs a sign-in); null when the visitor was not signed in yet.
            $table->foreignId('visitor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('visited_at');
            $table->index(['teach_referral_link_id', 'visited_at']);
            $table->index(['visitor_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teach_referral_visits');
        Schema::dropIfExists('teach_referral_links');
        Schema::dropIfExists('teach_student_notes');
        Schema::dropIfExists('teach_onboarding');
    }
};
