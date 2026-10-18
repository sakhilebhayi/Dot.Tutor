<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: every new column is nullable or has a default, so existing
 * rows and existing inserts keep working. Existing profiles and sessions are
 * stamped with the configured currency/timezone by the column default, which
 * is the truthful value for data created before these columns existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->string('headline', 160)->nullable()->after('user_id');
            $table->text('qualifications')->nullable()->after('bio');
            $table->string('timezone', 64)->default(config('tutor.timezone'))->after('qualifications');
            $table->string('currency', 3)->default(config('tutor.currency'))->after('hourly_rate');
            $table->text('suspended_reason')->nullable()->after('rejected_reason');
        });

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->string('currency', 3)->default(config('tutor.currency'))->after('rate');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('tutor_sessions', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('tutor_profiles', fn (Blueprint $table) => $table->dropColumn(['headline', 'qualifications', 'timezone', 'currency', 'suspended_reason']));
    }
};
