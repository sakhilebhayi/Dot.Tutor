<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking v2 on the existing tutor_sessions table (additive):
 *  - rate_minor, the integer companion of the decimal rate, backfilled;
 *  - the offering booked, the kind (one_to_one | group), group capacity,
 *    recurring series and package purchase links;
 *  - payment and guardian state, decision reasons, cancellation and timing;
 *  - tutor_session_participants: seats on a group session;
 *  - tutor_session_events: the append-only history of every state change;
 *  - marketplace_idempotency_keys: one row per Idempotency-Key, unique per user and scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_minor')->nullable()->after('rate');
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('kind', 20)->default('one_to_one');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('payment_status', 20)->default('not_required');
            $table->string('order_reference', 120)->nullable();
            $table->string('guardian_status', 20)->default('not_required');
            $table->text('decision_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('location_note', 200)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
        });

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreignId('tutor_offering_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tutor_booking_series_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tutor_package_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('tutor_sessions')->orderBy('id')->select(['id', 'rate'])->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tutor_sessions')->where('id', $row->id)->update([
                    'rate_minor' => (int) round(((float) $row->rate) * 100),
                ]);
            }
        });

        Schema::create('tutor_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('users')->nullOnDelete();
            // awaiting_guardian | awaiting_payment | confirmed | cancelled | attended | no_show
            $table->string('status', 20);
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('order_reference', 120)->nullable();
            $table->string('guardian_status', 20)->default('not_required');
            $table->foreignId('tutor_package_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tutor_session_id', 'learner_id']);
            $table->index(['learner_id', 'status']);
        });

        Schema::create('tutor_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tutor_session_id', 'id']);
        });

        Schema::create('marketplace_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->string('idempotency_key', 120);
            $table->char('request_hash', 64);
            $table->string('resource_type', 40)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'idempotency_key'], 'marketplace_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_idempotency_keys');
        Schema::dropIfExists('tutor_session_events');
        Schema::dropIfExists('tutor_session_participants');

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('tutor_package_purchase_id');
            $table->dropConstrainedForeignId('tutor_booking_series_id');
            $table->dropConstrainedForeignId('tutor_offering_id');
        });
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'rate_minor', 'price_minor', 'kind', 'capacity', 'payment_status', 'order_reference',
                'guardian_status', 'decision_reason', 'cancelled_at', 'location_note', 'started_at',
                'ended_at', 'hold_expires_at',
            ]);
        });
    }
};
