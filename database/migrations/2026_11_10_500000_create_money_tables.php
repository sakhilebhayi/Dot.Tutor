<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R-47 money domain (docs/R47_MONEY.md). Every amount is an integer in minor units next to an ISO
     * currency. Orders, payments, refunds, payouts and coupon redemptions carry unique idempotency keys or
     * references so a double click, a duplicate webhook or a retried job can never move money twice.
     */
    public function up(): void
    {
        Schema::create('money_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // stored upper case
            $table->string('funded_by', 10); // tutor | platform
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete(); // the tutor for tutor-funded
            $table->string('kind', 10); // percent | fixed
            $table->unsignedInteger('percent_bps')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3);
            $table->string('applies_to_type', 30)->nullable(); // purchasable type or null = any
            $table->unsignedBigInteger('min_order_minor')->default(0);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('money_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->index(); // pending_payment | awaiting_guardian | paid | cancelled | expired | guardian_refused | refunded | partially_refunded
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->unsignedBigInteger('total_minor'); // what is charged to a card
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained('money_coupons')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique(); // "{buyer}:{key}" hashed
            $table->string('guardian_status', 16)->default('not_needed'); // not_needed | pending | approved | refused
            $table->string('guardian_reference', 100)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
        });

        Schema::create('money_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('money_orders')->restrictOnDelete();
            $table->string('purchasable_type', 30);
            $table->unsignedBigInteger('purchasable_id');
            $table->string('title', 200);
            $table->foreignId('seller_id')->nullable()->constrained('users')->restrictOnDelete(); // null = the platform
            $table->string('commission_category', 60);
            $table->string('earning_release', 16); // on_delivery | after_window
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('tutor_discount_minor')->default(0);
            $table->unsignedBigInteger('platform_discount_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->unsignedBigInteger('cash_minor'); // price - discounts - credit
            $table->unsignedInteger('commission_bps');
            $table->foreignId('commission_rule_id')->nullable();
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('charges_minor')->default(0);
            $table->unsignedBigInteger('seller_net_minor');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->timestamps();

            $table->index(['purchasable_type', 'purchasable_id']);
            $table->index('seller_id');
        });

        Schema::create('money_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('money_coupons')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('money_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('discount_minor');
            $table->string('status', 12); // reserved | confirmed | released
            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'user_id', 'status']);
        });

        Schema::create('money_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('money_orders')->restrictOnDelete();
            $table->string('provider', 16); // paystack | none (fully covered by credit or a discount)
            $table->string('reference', 100)->unique();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 16)->index(); // initialised | paid | failed | abandoned
            $table->text('authorization_url')->nullable(); // encrypted: reopened within the attempt TTL
            $table->string('provider_transaction_id', 60)->nullable();
            $table->string('channel', 30)->nullable();
            $table->unsignedBigInteger('fees_minor')->nullable();
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'attempt']);
        });

        Schema::create('money_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('event_key', 64)->unique();
            $table->string('event', 60);
            $table->string('reference', 120)->nullable()->index();
            $table->json('payload');
            $table->string('status', 12)->default('received'); // received | processing | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('outcome', 160)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('money_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60)->nullable(); // null = the platform default
            $table->unsignedInteger('percent_bps');
            $table->unsignedInteger('version');
            $table->timestamp('effective_from');
            $table->timestamp('superseded_at')->nullable();
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'effective_from']);
        });

        Schema::create('money_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->text('holder_name'); // encrypted
            $table->text('account_number'); // encrypted
            $table->string('account_last4', 4);
            $table->string('bank_name', 80);
            $table->string('bank_code', 20);
            $table->string('account_type', 20)->default('personal');
            $table->string('blind_index', 64)->index(); // HMAC of bank code + number: one account, two payees
            $table->string('recipient_code', 60)->nullable(); // Paystack transfer recipient
            $table->timestamps();
        });

        Schema::create('money_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12); // draft | exported | closed
            $table->timestamp('period_end');
            $table->string('currency', 3);
            $table->unsignedInteger('payout_count')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->json('carried_forward')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('money_payout_batches')->restrictOnDelete();
            $table->foreignId('payee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained('money_payout_accounts')->nullOnDelete();
            $table->string('reference', 60)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('method', 20)->default('manual'); // manual | paystack_transfer
            $table->string('status', 12)->index(); // draft | processing | paid | failed
            $table->string('transfer_code', 60)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('money_order_lines')->restrictOnDelete();
            $table->string('source_key', 80)->unique(); // sale:{line} | refund:{refund}
            $table->string('kind', 12); // sale | reversal
            $table->bigInteger('gross_minor'); // the tutor's selling price after their own discount
            $table->bigInteger('commission_minor');
            $table->bigInteger('charges_minor');
            $table->bigInteger('net_minor'); // negative for a reversal
            $table->string('currency', 3);
            $table->string('status', 12)->index(); // pending | available | paid | cancelled
            $table->string('release_rule', 16);
            $table->timestamp('release_after')->nullable(); // after_window: the window end; on_delivery: set when delivered
            $table->timestamp('available_at')->nullable();
            $table->foreignId('payout_id')->nullable()->constrained('money_payouts')->nullOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('money_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('money_orders')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('money_order_lines')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('money_payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason', 40);
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->index(); // requested | pending | processing | processed | failed
            $table->string('provider_refund_id', 60)->nullable()->index();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('failure_message', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('money_payments')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('money_orders')->nullOnDelete();
            $table->string('provider_dispute_id', 60)->nullable()->unique();
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 16); // open | won | lost | resolved
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_minor'); // negative for a revocation
            $table->string('currency', 3);
            $table->string('reason', 255);
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('money_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('entitled_type', 30);
            $table->unsignedBigInteger('entitled_id');
            $table->string('source', 10); // paid | free | gifted | bundle
            $table->foreignId('order_line_id')->nullable()->constrained('money_order_lines')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'entitled_type', 'entitled_id']);
            $table->unique(['user_id', 'entitled_type', 'entitled_id', 'order_line_id'], 'money_entitlements_line_unique');
        });

        Schema::create('money_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('money_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('money_bundles')->cascadeOnDelete();
            $table->string('purchasable_type', 30);
            $table->unsignedBigInteger('purchasable_id');
            $table->timestamps();

            $table->unique(['bundle_id', 'purchasable_type', 'purchasable_id']);
        });

        Schema::create('money_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->unique()->constrained('money_orders')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('money_finance_issues', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('severity', 10)->default('warning'); // info | warning | critical
            $table->string('dedupe_key', 160)->unique();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('status', 12)->default('open'); // open | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
        });

        Schema::create('money_sequences', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'money_sequences', 'money_finance_issues', 'money_receipts', 'money_bundle_items', 'money_bundles',
            'money_entitlements', 'money_credit_grants', 'money_disputes', 'money_refunds', 'money_earnings',
            'money_payouts', 'money_payout_batches', 'money_payout_accounts', 'money_commission_rules',
            'money_webhook_events', 'money_payments', 'money_coupon_redemptions', 'money_order_lines',
            'money_orders', 'money_coupons',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
