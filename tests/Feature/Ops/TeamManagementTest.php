<?php

namespace Tests\Feature\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $admin, ['admin']);

        return $admin->fresh();
    }

    public function test_the_first_administrator_is_granted_from_the_console_and_audited(): void
    {
        $user = User::factory()->create(['email' => 'Owner@Example.com']);

        $this->artisan('ops:grant', ['email' => 'owner@example.com'])->assertSuccessful();
        $this->assertTrue((bool) $user->fresh()->is_platform_operator);
        $this->assertSame(['admin'], app(OpsAccess::class)->roles($user->fresh()));
        $this->assertTrue(AuditEntry::query()->where('action', 'ops.roles.changed')->whereNull('actor_id')->exists());

        $this->artisan('ops:grant', ['email' => 'owner@example.com', '--role' => 'finance', '--add' => true])->assertSuccessful();
        app(OpsAccess::class)->forget($user);
        $this->assertSame(['admin', 'finance'], app(OpsAccess::class)->roles($user->fresh()));

        $this->artisan('ops:grant', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('ops:grant', ['email' => 'owner@example.com', '--role' => 'wizard'])->assertFailed();
    }

    public function test_an_administrator_adds_changes_and_removes_operators(): void
    {
        $admin = $this->admin();
        $person = User::factory()->create(['email' => 'reviewer@example.com']);

        $this->actingAs($admin)->post(route('ops.users.team.store'), ['email' => 'REVIEWER@example.com', 'roles' => ['verification_reviewer']])->assertRedirect();
        $this->assertSame(['verification_reviewer'], app(OpsAccess::class)->roles($person->fresh()));

        $this->actingAs($admin)->post(route('ops.users.team.update', $person), ['roles' => ['verification_reviewer', 'content_moderator']])->assertRedirect();
        app(OpsAccess::class)->forget($person);
        $this->assertSame(['content_moderator', 'verification_reviewer'], app(OpsAccess::class)->roles($person->fresh()));

        $entry = AuditEntry::query()->where('action', 'ops.roles.changed')->where('subject_id', $person->id)->orderByDesc('id')->first();
        $this->assertSame(['verification_reviewer'], $entry->context['before']);

        $this->actingAs($admin)->post(route('ops.users.team.remove', $person))->assertRedirect();
        $this->assertFalse((bool) $person->fresh()->is_platform_operator);
        $this->assertTrue(AuditEntry::query()->where('action', 'ops.operator.removed')->exists());
    }

    public function test_someone_who_never_signed_in_cannot_be_added(): void
    {
        $this->actingAs($this->admin())->post(route('ops.users.team.store'), ['email' => 'ghost@example.com', 'roles' => ['finance']])
            ->assertSessionHasErrors('email');
    }

    public function test_the_last_administrator_cannot_be_demoted_and_nobody_removes_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('ops.users.team.update', $admin), ['roles' => ['finance']])->assertSessionHasErrors('roles');
        $this->actingAs($admin)->post(route('ops.users.team.remove', $admin))->assertSessionHasErrors('user');
        $this->assertTrue(app(OpsAccess::class)->isAdmin($admin->fresh()));
    }

    public function test_only_administrators_manage_roles(): void
    {
        foreach (OpsRole::cases() as $role) {
            if ($role === OpsRole::Admin) {
                continue;
            }
            $user = User::factory()->create();
            app(RoleAdministration::class)->setRoles(null, $user, [$role->value]);
            $target = User::factory()->create();

            $this->actingAs($user->fresh())->get(route('ops.users.team'))->assertForbidden();
            $this->actingAs($user->fresh())->post(route('ops.users.team.update', $target), ['roles' => ['admin']])->assertForbidden();
            $this->assertFalse((bool) $target->fresh()->is_platform_operator);
        }
    }
}
