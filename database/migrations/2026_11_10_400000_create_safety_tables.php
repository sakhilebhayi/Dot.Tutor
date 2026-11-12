<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 safety domain (app/Domain/Safety). Additive only: no existing table changes.
 *
 * People rows are restricted, not cascaded, wherever the row is safeguarding evidence (reports, incidents,
 * audit, screening): deleting an account must not silently delete the record of what happened to a child.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A date of birth the safety domain itself holds: confirmed by a guardian at consent or recorded by an
        // operator. It outranks the learner's self-declared date of birth from the academic profile.
        Schema::create('safety_age_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('birth_date');
            $table->string('source', 32); // guardian_declared, operator_verified
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('safety_guardian_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('relationship', 32);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'created_at']);
        });

        Schema::create('safety_guardian_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('safety_guardian_invitations')->nullOnDelete();
            $table->string('relationship', 32);
            $table->string('status', 16); // active, revoked
            $table->json('approval_settings')->nullable(); // a teen's per-kind overrides, set by the guardian
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'guardian_id']);
            $table->index(['guardian_id', 'status']);
        });

        // One row per consent given; withdrawal stamps the row, it is never deleted.
        Schema::create('safety_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guardian_link_id')->nullable()->constrained('safety_guardian_links')->nullOnDelete();
            $table->string('kind', 32); // processing, lesson_recording
            $table->string('version', 32);
            $table->string('text_hash', 64);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'kind']);
        });

        Schema::create('safety_guardian_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32); // booking, purchase, paid_room, live_lesson
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 16); // pending, approved, declined, expired, cancelled
            $table->string('summary', 255)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'kind']);
            $table->index(['learner_id', 'status']);
        });

        Schema::create('safety_screening_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 48);
            $table->string('status', 16); // pending_review, cleared, not_cleared, revoked
            $table->string('reference', 120)->nullable();
            $table->date('checked_on');
            $table->date('expires_on')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'status']);
        });

        Schema::create('safety_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('context_type', 16); // booking, room, support, direct
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('subject', 160)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('involves_minor')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['context_type', 'context_id']);
        });

        Schema::create('safety_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('safety_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16); // member, guardian, operator
            $table->foreignId('guardian_of_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('safety_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('safety_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->text('original_body')->nullable(); // encrypted; only when something was redacted
            $table->json('redactions')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('safety_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('safety_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();
        });

        Schema::create('safety_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('title', 200);
            $table->string('severity', 16); // critical, high, medium
            $table->string('status', 24); // open, acknowledged, referred, closed
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->timestamps();

            $table->index(['status', 'respond_by']);
        });

        Schema::create('safety_incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('safety_incidents')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 32);
            $table->text('body')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('safety_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('priority', 16);
            $table->string('status', 16); // open, actioned, dismissed, escalated
            $table->text('details')->nullable();
            $table->boolean('involves_minor')->default(false);
            $table->json('snapshot')->nullable();
            $table->timestamp('respond_by');
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('safety_incidents')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'respond_by']);
            $table->index(['reportable_type', 'reportable_id']);
        });

        Schema::create('safety_moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('safety_reports')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('safety_incidents')->restrictOnDelete();
            $table->string('action', 16); // hide, restore, warn, suspend, unsuspend, escalate, dismiss
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->json('meta')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('safety_account_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16); // suspension, warning
            $table->string('reason', 500);
            $table->foreignId('imposed_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });

        Schema::create('safety_integrity_cases', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('report_id')->nullable()->constrained('safety_reports')->restrictOnDelete();
            $table->foreignId('opened_by_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16); // open, upheld, not_upheld
            $table->text('summary');
            $table->text('finding')->nullable();
            $table->string('sanction', 32)->nullable(); // none, warning, content_removed, review_removed, label_removed, suspension
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
        });

        // Append-only and hash-chained: each row's hash covers the previous row's hash, so an edited or deleted
        // row breaks the chain (`php artisan safety:audit-verify`). The model refuses updates and deletes.
        Schema::create('safety_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable(); // no FK: the log outlives accounts
            $table->string('actor_label', 120)->nullable();
            $table->string('action', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64);
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        foreach ([
            'safety_audit_log', 'safety_integrity_cases', 'safety_account_restrictions', 'safety_moderation_actions',
            'safety_reports', 'safety_incident_events', 'safety_incidents', 'safety_message_attachments',
            'safety_messages', 'safety_conversation_participants', 'safety_conversations', 'safety_screening_checks',
            'safety_guardian_approvals', 'safety_consents', 'safety_guardian_links', 'safety_guardian_invitations',
            'safety_age_records',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
