<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 community domain (docs/R47_COMMUNITY.md): learning rooms derived from academic offerings, posts (questions,
 * answers, discussions, explanations, revision techniques, shared material), study groups, scheduled study
 * sessions and workshops, revision lists, group tasks. All additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12); // subject | tutor
            // "offering:{id}:{audience}" for subject rooms (one per offering and audience); null for tutor rooms.
            $table->string('subject_key', 60)->nullable()->unique();
            $table->foreignId('curriculum_subject_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('academic_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('audience', 12); // under_18 | adult
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('language', 20)->nullable(); // BCP 47 teaching language of a tutor room
            $table->string('join_rule', 12)->default('open'); // open | approval | invite
            $table->string('invite_code', 24)->nullable()->unique();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedSmallInteger('seats_taken')->default(0);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 12)->default('active'); // active | archived
            $table->timestamps();

            $table->index(['academic_level_id', 'audience', 'kind']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('community_room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('community_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('learner'); // learner | tutor | owner
            $table->string('status', 20); // active | pending | awaiting_guardian | awaiting_payment | left | removed
            $table->boolean('holds_seat')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('community_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('community_rooms')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('topic', 160)->nullable();
            $table->string('exam_focus', 160)->nullable();
            $table->string('schedule_note', 200)->nullable();
            $table->unsignedSmallInteger('size_cap');
            $table->unsignedSmallInteger('members_count')->default(0);
            $table->string('join_rule', 12)->default('open');
            $table->string('invite_code', 24)->nullable()->unique();
            $table->string('status', 12)->default('active');
            $table->timestamps();

            $table->index(['room_id', 'status']);
        });

        Schema::create('community_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('member'); // owner | member
            $table->string('status', 12); // active | pending | left | removed
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('community_rooms')->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('community_groups')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16); // question | discussion | explanation | technique | material | answer | reply
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->text('original_text')->nullable(); // encrypted, only when contact details were hidden
            $table->unsignedSmallInteger('redactions')->default(0);
            $table->string('original_locale', 20)->default('en');
            $table->unsignedBigInteger('library_resource_id')->nullable(); // learning_resources.id (learning owns files)
            $table->string('status', 12)->default('visible'); // visible | held | hidden | removed
            $table->string('held_reason', 40)->nullable();
            $table->unsignedSmallInteger('reporters_count')->default(0);
            $table->foreignId('accepted_answer_id')->nullable()->constrained('community_posts')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'kind', 'status', 'last_activity_at']);
            $table->index(['parent_id', 'status']);
            $table->index(['author_id', 'kind']);
            $table->index(['status', 'held_reason']);
        });

        Schema::create('community_post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('community_post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('safety_report_id')->nullable();
            $table->string('category', 40);
            $table->timestamps();

            $table->unique(['post_id', 'reporter_id']);
        });

        Schema::create('community_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('community_rooms')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->string('where_note', 200)->nullable();
            $table->string('uid', 64)->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'starts_at']);
            $table->index(['room_id', 'starts_at']);
        });

        Schema::create('community_session_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('community_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['session_id', 'user_id']);
        });

        Schema::create('community_group_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('library_resource_id');
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'library_resource_id']);
        });

        Schema::create('community_revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->unsignedBigInteger('topic_id')->nullable(); // academic_topics.id
            $table->timestamps();
        });

        Schema::create('community_revision_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('community_revision_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'user_id']);
        });

        Schema::create('community_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamps();
        });

        Schema::create('community_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('community_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->string('done_note', 200)->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'community_task_assignees', 'community_tasks', 'community_revision_ticks', 'community_revision_items',
            'community_group_resources', 'community_session_attendance', 'community_sessions', 'community_post_reports',
            'community_post_revisions', 'community_posts', 'community_group_members', 'community_groups',
            'community_room_members', 'community_rooms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
