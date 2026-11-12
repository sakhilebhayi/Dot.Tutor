<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\AuditLog;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Services\DatabaseAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_the_seam_is_bound_to_the_database_log(): void
    {
        $this->assertInstanceOf(DatabaseAuditLog::class, app(AuditLog::class));
    }

    public function test_entries_chain_and_verify(): void
    {
        $actor = $this->adult();
        Audit::record($actor, 'money.payout.approved', $actor, ['amount_minor' => 150000, 'currency' => 'ZAR']);
        Audit::record(null, 'safety.test.system', 'scheduler');
        Audit::record($actor, 'marketplace.tutor.verified', null, ['level' => 'qualified', 'nested' => ['b' => 1, 'a' => 2]]);

        $entries = AuditEntry::query()->orderBy('id')->get();
        $this->assertSame($entries[0]->hash, $entries[1]->prev_hash);
        $this->assertSame('system', $entries[1]->actor_label);
        $this->assertNull(app(DatabaseAuditLog::class)->verify());
        $this->artisan('safety:audit-verify')->assertSuccessful();
    }

    public function test_a_change_made_around_the_model_breaks_the_chain(): void
    {
        $actor = $this->adult();
        foreach (range(1, 3) as $i) {
            Audit::record($actor, 'safety.test.'.$i, $actor);
        }
        $middle = AuditEntry::query()->orderBy('id')->skip(1)->first();

        DB::table('safety_audit_log')->where('id', $middle->id)->update(['action' => 'safety.test.rewritten']);

        $this->assertSame($middle->id, app(DatabaseAuditLog::class)->verify());
        $this->artisan('safety:audit-verify')->assertFailed();
    }

    public function test_a_deleted_row_breaks_the_chain(): void
    {
        $actor = $this->adult();
        foreach (range(1, 3) as $i) {
            Audit::record($actor, 'safety.test.'.$i, $actor);
        }
        $ids = AuditEntry::query()->orderBy('id')->pluck('id');

        DB::table('safety_audit_log')->where('id', $ids[1])->delete();

        $this->assertSame($ids[2], app(DatabaseAuditLog::class)->verify());
    }

    public function test_the_model_refuses_updates_and_deletes(): void
    {
        Audit::record(null, 'safety.test', null);

        try {
            AuditEntry::sole()->update(['action' => 'x']);
            $this->fail('update allowed');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        AuditEntry::sole()->delete();
    }

    public function test_operators_read_the_trail_with_its_chain_status(): void
    {
        $operator = $this->operator();
        Audit::record($operator, 'safety.test.read', $operator);

        $this->actingAs($operator)->getJson(route('safety.operator.audit', ['action' => 'safety.test']))
            ->assertOk()->assertJsonPath('entries.0.action', 'safety.test.read')->assertJsonPath('chain_broken_at', null);
        $this->actingAs($this->adult())->getJson(route('safety.operator.audit'))->assertForbidden();
    }
}
