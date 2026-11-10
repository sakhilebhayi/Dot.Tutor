<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * R-47 classroom (D-800 onward): one live classroom per booked lesson (tutor_sessions), the people in it, the short-lived
 * WebRTC signalling messages between their browsers, attendance spans from real joins, chat, the whiteboard ops log and
 * the tutor's after-lesson record. No recording table: recording is not built (docs/R47_CLASSROOM.md section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->unique()->constrained('tutor_sessions')->cascadeOnDelete();
            $table->string('room_key', 40)->unique();
            // scheduled | live | ended
            $table->string('status', 12)->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 24)->nullable();
            // The tutor's own meeting link, the fallback when browsers cannot connect directly (no TURN server).
            $table->string('external_url', 500)->nullable();
            $table->text('tutor_notes')->nullable();
            $table->text('summary')->nullable();
            $table->string('summary_source', 12)->nullable(); // tutor (an AI draft would be 'ai_draft', never published unreviewed)
            $table->timestamp('summary_published_at')->nullable();
            $table->text('shared_notes')->nullable();
            $table->unsignedInteger('shared_notes_version')->default(0);
            $table->foreignId('shared_notes_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('board_pages')->default(1);
            $table->timestamp('reminder_emitted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('classroom_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10); // tutor | learner | guardian
            // joined | left | removed
            $table->string('state', 10)->default('left');
            $table->string('client_id', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('mic_on')->default(false);
            $table->boolean('cam_on')->default(false);
            $table->boolean('sharing')->default(false);
            $table->string('quality', 8)->nullable();
            $table->unsignedSmallInteger('join_count')->default(0);
            $table->timestamps();

            $table->unique(['classroom_id', 'user_id']);
            $table->index(['classroom_id', 'state']);
        });

        Schema::create('classroom_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);
            $table->timestamp('joined_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('left_at')->nullable();
            $table->string('left_reason', 16)->nullable(); // left | lost | removed | ended
            $table->timestamps();

            $table->index(['classroom_id', 'user_id']);
        });

        Schema::create('classroom_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_attendee_id')->constrained('classroom_attendees')->cascadeOnDelete();
            $table->foreignId('to_attendee_id')->nullable()->constrained('classroom_attendees')->cascadeOnDelete();
            $table->string('type', 12); // offer | answer | candidate | command
            $table->text('payload');
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['classroom_id', 'id']);
            $table->index('created_at');
        });

        Schema::create('classroom_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->text('original_body')->nullable(); // encrypted; only when something was hidden
            $table->json('redactions')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->index(['classroom_id', 'id']);
        });

        Schema::create('classroom_board_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('kind', 10); // pen | erase | line | rect | ellipse | text | clear
            $table->json('data');
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['classroom_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_board_ops');
        Schema::dropIfExists('classroom_messages');
        Schema::dropIfExists('classroom_signals');
        Schema::dropIfExists('classroom_attendance');
        Schema::dropIfExists('classroom_attendees');
        Schema::dropIfExists('classrooms');
    }
};
