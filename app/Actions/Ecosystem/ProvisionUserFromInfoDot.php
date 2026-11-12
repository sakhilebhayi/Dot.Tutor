<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an InfoDot identity (already proven by the redeem endpoint) into a local user.
 *
 * Matches by verified email. An existing account whose email was never verified
 * here is taken over safely: its password is replaced, so whoever pre-registered
 * the address cannot keep using it. A person InfoDot vouches for but who has no
 * account here gets one, with a random password and a personal team.
 */
class ProvisionUserFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity
     */
    public function __invoke(array $identity): ?User
    {
        $email = Str::lower(trim((string) ($identity['user']['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($identity['user']['email_verified'] ?? false) !== true) {
            return null;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            if ($user->email_verified_at === null) {
                // Whoever pre-registered or switched to this address loses every way back in (R-47 security S-01):
                // the password, two-factor secret, remember-me token, API tokens and every open session.
                $user->forceFill([
                    'password' => Hash::make(Str::random(48)),
                    'email_verified_at' => now(),
                    'remember_token' => Str::random(60),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ])->save();
                $user->tokens()->delete();

                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                Log::notice('Unverified local account adopted by InfoDot sign-in; credentials and sessions replaced.', ['user_id' => $user->id]);
            }

            return $user;
        }

        if (! config('ecosystem.infodot.provision', true)) {
            return null;
        }

        return DB::transaction(function () use ($identity, $email) {
            $name = trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@');

            $user = new User(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(48))]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);
            $user->ownedTeams()->save($team);
            $user->switchTeam($team);

            return $user;
        });
    }
}
