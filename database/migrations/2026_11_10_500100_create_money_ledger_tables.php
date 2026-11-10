<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Double-entry ledger (adapted from Dot.Ehail R-46, proven on the same host). A journal is one business
     * event; its entries are the debit and credit lines. Append-only: the model refuses updates and deletes,
     * and database triggers refuse UPDATE and DELETE on SQLite and PostgreSQL (MySQL: attempted, logged if the
     * host refuses trigger privileges). Every entry is exactly one positive debit or credit. On PostgreSQL a
     * deferred constraint trigger refuses to COMMIT a journal whose sides differ. Receipts are immutable too.
     */
    public function up(): void
    {
        Schema::create('money_ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('idempotency_key', 160)->unique();
            $table->string('currency', 3);
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('refund_id')->nullable()->index();
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->unsignedBigInteger('earning_id')->nullable()->index();
            $table->string('reason', 500);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'posted_at']);
        });

        Schema::create('money_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('money_ledger_journals')->restrictOnDelete();
            $table->string('account', 80); // e.g. tutor_payable:12
            $table->string('account_type', 40);
            $table->unsignedBigInteger('party_id')->nullable();
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->string('currency', 3);
            $table->timestamp('created_at')->nullable();

            $table->index(['account', 'currency']);
            $table->index(['account_type', 'party_id']);
        });

        $this->installGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('money_ledger_entries');
        Schema::dropIfExists('money_ledger_journals');
    }

    private function installGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        $immutable = ['money_ledger_journals', 'money_ledger_entries', 'money_receipts'];

        if ($driver === 'sqlite') {
            foreach ($immutable as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'money records are immutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'money records are immutable'); END;");
            }

            DB::unprepared("CREATE TRIGGER money_ledger_entries_one_sided BEFORE INSERT ON money_ledger_entries
                WHEN NOT ((NEW.debit_minor > 0 AND NEW.credit_minor = 0) OR (NEW.credit_minor > 0 AND NEW.debit_minor = 0))
                BEGIN SELECT RAISE(ABORT, 'a ledger entry is exactly one positive debit or credit'); END;");

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION money_refuse_change() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'money records are immutable'; END; \$\$ LANGUAGE plpgsql;");

            foreach ($immutable as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION money_refuse_change();");
            }

            DB::unprepared('ALTER TABLE money_ledger_entries ADD CONSTRAINT money_ledger_entries_one_sided CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');

            DB::unprepared("CREATE OR REPLACE FUNCTION money_journal_balanced() RETURNS trigger AS \$\$
                DECLARE diff numeric;
                BEGIN
                    SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) INTO diff FROM money_ledger_entries WHERE journal_id = NEW.journal_id;
                    IF diff <> 0 THEN RAISE EXCEPTION 'ledger journal % does not balance', NEW.journal_id; END IF;
                    RETURN NULL;
                END; \$\$ LANGUAGE plpgsql;");

            DB::unprepared('CREATE CONSTRAINT TRIGGER money_ledger_entries_balanced AFTER INSERT ON money_ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION money_journal_balanced();');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                foreach ($immutable as $table) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'money records are immutable'");
                    DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'money records are immutable'");
                }
            } catch (Throwable $e) {
                Log::warning('money.ledger_triggers_not_installed', ['driver' => $driver, 'error' => class_basename($e)]);
            }
        }
    }

    private function dropGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS money_ledger_entries_balanced ON money_ledger_entries');

            foreach (['money_ledger_journals', 'money_ledger_entries', 'money_receipts'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS money_journal_balanced()');
            DB::unprepared('DROP FUNCTION IF EXISTS money_refuse_change()');

            return;
        }

        foreach (['money_ledger_journals', 'money_ledger_entries', 'money_receipts'] as $table) {
            foreach (['no_update', 'no_delete'] as $suffix) {
                try {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$table}_{$suffix}");
                } catch (Throwable) {
                    // Not installed on this driver.
                }
            }
        }

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS money_ledger_entries_one_sided');
        } catch (Throwable) {
            // Not installed on this driver.
        }
    }
};
