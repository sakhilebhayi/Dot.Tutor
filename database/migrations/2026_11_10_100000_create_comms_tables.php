<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 comms (docs/R47_COMMS.md): per-person channel choices and quiet hours, the delivery log (one row per event,
 * person and channel, which is also the work queue), and Web Push subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('channel', 16); // push | email | sms
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel'], 'comms_preferences_unique');
        });

        Schema::create('comms_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quiet_enabled')->default(true);
            $table->string('quiet_start', 5)->default('21:00'); // HH:MM local
            $table->string('quiet_end', 5)->default('07:00');
            $table->string('timezone', 64)->nullable(); // null: the tutor profile's, else the app's
            $table->timestamps();
        });

        Schema::create('comms_deliveries', function (Blueprint $table) {
            $table->id();
            // One occurrence of one event ("booking-event:12", "lesson:7:reminder:24h:1762790400", "n:<uuid>").
            $table->string('event_key', 160);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // in_app | push | email | sms | notify
            $table->string('category', 40);
            $table->string('type', 60);
            // The in-app record this copy is made from (content is read from it, never stored twice).
            $table->uuid('notification_id')->nullable();
            $table->string('status', 12); // queued | sending | sent | failed | skipped
            $table->string('reason', 160)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('collapse_key', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['event_key', 'user_id', 'channel'], 'comms_deliveries_idempotency');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['notification_id']);
            $table->index(['user_id', 'collapse_key', 'channel']);
        });

        Schema::create('comms_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint'); // encrypted
            $table->char('endpoint_hash', 64)->unique();
            $table->text('p256dh'); // encrypted
            $table->text('auth_secret'); // encrypted
            $table->string('device_label', 60)->nullable(); // "Chrome on Android"; never the full user agent
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_push_subscriptions');
        Schema::dropIfExists('comms_deliveries');
        Schema::dropIfExists('comms_user_settings');
        Schema::dropIfExists('comms_preferences');
    }
};
