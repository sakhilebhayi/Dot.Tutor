<?php

namespace App\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Domain\Safety\Models\GuardianLink;
use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * What the shared sign-in package needs to know about Tutor's users table. Matching order and the claim flow live in
 * the package; this class only reads and writes users, and enforces Tutor's safeguarding rules at the two points that
 * matter:
 *
 *  - create(): only through NewAccountAdmission (an invited guardian). Never for an unknown person.
 *  - hasVerifiedEmail(): an account that carries authority or a child's protection (operator, tutor profile, any
 *    guardian link on either side) is never linked silently by email, even when both sides say verified. It goes
 *    through the emailed-code claim flow once.
 *
 * It never touches a password, 2FA settings, API tokens, sessions, team memberships, age records or guardian links.
 */
class TutorProvisioner implements UserProvisioner
{
    public function findByUuid(string $uuid): ?Authenticatable
    {
        return User::query()->where('infodot_user_uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->infodot_user_uuid : null;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        if (! $user instanceof User || $user->email_verified_at === null) {
            return false;
        }

        return ! self::carriesAuthority($user);
    }

    /** Operators, tutors (payouts) and anyone on either side of a guardian link: proof of the mailbox first, every time. */
    public static function carriesAuthority(User $user): bool
    {
        return (bool) $user->is_platform_operator
            || $user->tutorProfile()->exists()
            || GuardianLink::query()->where(fn ($q) => $q->where('learner_id', $user->id)->orWhere('guardian_id', $user->id))->exists();
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        $user->forceFill(['infodot_user_uuid' => $uuid])->save();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        if (! NewAccountAdmission::allowed($claims['email'])) {
            Log::notice('Central sign-in refused: no account here and no usable guardian invitation for this address.');

            return null;
        }

        return app(ProvisionUserFromInfoDot::class)->create($claims['email'], $claims['name'], $claims['sub']);
    }

    public function afterSignIn(Authenticatable $user, array $claims): void
    {
        // Nothing: company teams are not in the OIDC token. The personal workspace is made at creation.
    }

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        Mail::to($user->email)->send(new SsoClaimCodeMail($user->name, $code, $minutes));
    }
}
