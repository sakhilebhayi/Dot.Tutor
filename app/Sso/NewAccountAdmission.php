<?php

namespace App\Sso;

use App\Domain\Safety\Models\GuardianInvitation;
use Illuminate\Support\Str;

/**
 * CHILD-SAFEGUARDING RULE (R-54): who may get a NEW Dot.Tutor account out of a central (InfoDot) sign-in.
 *
 * InfoDot registration collects no age, so an InfoDot identity says nothing about whether the person is a child.
 * Tutor therefore never opens an account just because InfoDot vouches for an email. The one door left is the existing
 * guardian invitation: a learner (already here, with their own age handling) invited this exact address, the
 * invitation is still usable (not accepted, not revoked, not expired), and InfoDot has verified the address. The new
 * account is bare: no learner profile, no tutor profile, no age record. AgeResolver (default `ask`) and the young-learner
 * step still decide everything else, and the guardian link itself still needs the signed link, the invitation token,
 * a birth date and consent (Guardians::accept).
 *
 * While DOTSSO_ENABLED is false nothing here applies: the old /auth/ecosystem receiver behaves exactly as before.
 */
final class NewAccountAdmission
{
    public static function allowed(string $email): bool
    {
        $email = Str::lower(trim($email));

        if ($email === '') {
            return false;
        }

        return GuardianInvitation::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
