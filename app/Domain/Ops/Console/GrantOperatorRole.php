<?php

namespace App\Domain\Ops\Console;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Grant the first administrator (or any role) from the server, before anyone can use the team page.
 * The person must already exist (they signed in through InfoDot once). Audited with no actor ("system").
 */
class GrantOperatorRole extends Command
{
    protected $signature = 'ops:grant {email : The person\'s sign-in email} {--role=admin : One of '.'admin, academic_editor, verification_reviewer, safeguarding_officer, content_moderator, support_agent, finance'.'} {--add : Keep the roles they already hold}';

    protected $description = 'Make a person a Dot.Tutor operator with a role (the first administrator, typically)';

    public function handle(RoleAdministration $roles, OpsAccess $access): int
    {
        $role = OpsRole::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->error('Unknown role. Choose one of: '.implode(', ', array_column(OpsRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $this->argument('email'))])->first();
        if ($user === null) {
            $this->error('Nobody with that email has signed in yet. Ask them to sign in once through InfoDot, then run this again.');

            return self::FAILURE;
        }

        $keep = $this->option('add') && $user->is_platform_operator ? $access->roles($user) : [];

        try {
            $roles->setRoles(null, $user, array_values(array_unique([...$keep, $role->value])));
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info("{$user->email} is now an operator with: ".implode(', ', $access->roles($user)).'.');

        return self::SUCCESS;
    }
}
