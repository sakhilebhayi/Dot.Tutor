<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-47 security S-13: when a payout account's bank details last changed, for the cooling-off hold. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_payout_accounts', function (Blueprint $table) {
            $table->timestamp('details_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('money_payout_accounts', function (Blueprint $table) {
            $table->dropColumn('details_changed_at');
        });
    }
};
