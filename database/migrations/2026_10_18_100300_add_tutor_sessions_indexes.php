<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            // Overlap checks and slot computation: one tutor's sessions around a time.
            $table->index(['tutor_profile_id', 'starts_at'], 'tutor_sessions_tutor_starts_idx');
            // "My sessions by state" for students and the double-booking check.
            $table->index(['student_id', 'status'], 'tutor_sessions_student_status_idx');
            // Platform totals and the stale-session sweep.
            $table->index('status', 'tutor_sessions_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropIndex('tutor_sessions_tutor_starts_idx');
            $table->dropIndex('tutor_sessions_student_status_idx');
            $table->dropIndex('tutor_sessions_status_idx');
        });
    }
};
