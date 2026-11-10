<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\Consent;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Notifications\GuardianInvited;
use App\Domain\Safety\Support\ConsentText;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Linking a minor to a parent or guardian: invitation by email with a signed, expiring link; the guardian signs
 * in through InfoDot, confirms the learner's date of birth and gives consent; either side of the link can be
 * revoked by the guardian or an operator (never by the minor alone).
 */
class Guardians
{
    public const RELATIONSHIPS = ['parent', 'legal_guardian', 'caregiver'];

    public function __construct(
        private readonly AgeResolver $ages,
        private readonly AccountStanding $standing,
    ) {}

    /** @return array{invitation: GuardianInvitation, url: string} */
    public function invite(User $learner, string $email, string $relationship, ?User $invitedBy = null): array
    {
        $email = Str::lower(trim($email));

        if ($email === Str::lower((string) $learner->email)) {
            throw ValidationException::withMessages(['email' => 'Invite a parent or guardian, not your own address.']);
        }

        $sentToday = GuardianInvitation::query()->where('learner_id', $learner->id)
            ->where('created_at', '>', now()->subDay())->count();

        if ($sentToday >= (int) config('safety.guardians.invitations_per_day')) {
            throw ValidationException::withMessages(['email' => 'Too many invitations today. Try again tomorrow.']);
        }

        $token = Str::random(48);
        $expires = now()->addHours((int) config('safety.guardians.invitation_ttl_hours'));

        $invitation = GuardianInvitation::create([
            'learner_id' => $learner->id,
            'invited_by_id' => ($invitedBy ?? $learner)->id,
            'email' => $email,
            'relationship' => $relationship,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
        ]);

        $url = URL::temporarySignedRoute('safety.guardians.accept', $expires, [
            'invitation' => $invitation->id,
            'token' => $token,
        ]);

        Notification::route('mail', $email)->notify(new GuardianInvited($learner->name, $relationship, $url, $expires));

        Audit::record($invitedBy ?? $learner, 'safety.guardian.invited', $invitation, [
            'learner_id' => $learner->id, 'relationship' => $relationship,
        ]);

        return ['invitation' => $invitation, 'url' => $url];
    }

    public function findUsableInvitation(int $invitationId, string $token): ?GuardianInvitation
    {
        $invitation = GuardianInvitation::query()->find($invitationId);

        if (! $invitation || ! $invitation->isUsable() || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            return null;
        }

        return $invitation;
    }

    public function accept(GuardianInvitation $invitation, string $token, User $guardian, CarbonImmutable $learnerBirthDate, bool $recordingConsent = false): GuardianLink
    {
        if (! $invitation->isUsable() || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['invitation' => 'This invitation link is no longer valid. Ask for a new one.']);
        }

        if (config('safety.guardians.require_matching_email')
            && Str::lower((string) $guardian->email) !== Str::lower($invitation->email)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to a different email address. Sign in with that address.']);
        }

        if ($guardian->id === $invitation->learner_id) {
            throw ValidationException::withMessages(['invitation' => 'A learner cannot be their own guardian.']);
        }

        if ($this->ages->knownBand($guardian)?->isMinor()) {
            throw ValidationException::withMessages(['invitation' => 'A guardian must be 18 or older.']);
        }

        if ($this->standing->isSuspended($guardian)) {
            throw ValidationException::withMessages(['invitation' => 'This account is suspended and cannot be linked as a guardian.']);
        }

        if (! AgeBand::fromBirthDate($learnerBirthDate)->isMinor() || $learnerBirthDate->isFuture()) {
            throw ValidationException::withMessages(['birth_date' => 'A guardian link is for learners under 18. Check the date of birth.']);
        }

        $learner = $invitation->learner;

        $link = DB::transaction(function () use ($invitation, $guardian, $learner, $learnerBirthDate, $recordingConsent) {
            AgeRecord::create([
                'user_id' => $learner->id,
                'birth_date' => $learnerBirthDate->toDateString(),
                'source' => 'guardian_declared',
                'recorded_by_id' => $guardian->id,
            ]);

            $link = GuardianLink::query()->updateOrCreate(
                ['learner_id' => $learner->id, 'guardian_id' => $guardian->id],
                [
                    'invitation_id' => $invitation->id,
                    'relationship' => $invitation->relationship,
                    'status' => GuardianLink::ACTIVE,
                    'revoked_at' => null, 'revoked_by_id' => null, 'revoke_reason' => null,
                ],
            );

            $this->grant($link, Consent::PROCESSING);

            if ($recordingConsent) {
                $this->grant($link, Consent::LESSON_RECORDING);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by_id' => $guardian->id])->save();

            return $link;
        });

        app(Messenger::class)->syncGuardians($learner);

        Audit::record($guardian, 'safety.guardian.linked', $link, [
            'learner_id' => $learner->id,
            'consent_version' => config('safety.guardians.consent_version'),
            'recording_consent' => $recordingConsent,
        ]);

        return $link;
    }

    public function revoke(GuardianLink $link, User $by, ?string $reason = null): void
    {
        if ($link->status !== GuardianLink::ACTIVE) {
            return;
        }

        DB::transaction(function () use ($link, $by, $reason) {
            $link->forceFill([
                'status' => GuardianLink::REVOKED,
                'revoked_at' => now(),
                'revoked_by_id' => $by->id,
                'revoke_reason' => $reason,
            ])->save();

            Consent::query()->where('guardian_link_id', $link->id)->inForce()->update(['withdrawn_at' => now()]);
        });

        app(Messenger::class)->syncGuardians($link->learner);

        Audit::record($by, 'safety.guardian.revoked', $link, ['learner_id' => $link->learner_id, 'reason' => $reason]);
    }

    public function setRecordingConsent(GuardianLink $link, bool $consent, User $by): void
    {
        if ($consent) {
            if (! $this->linkHasConsent($link, Consent::LESSON_RECORDING)) {
                $this->grant($link, Consent::LESSON_RECORDING);
            }
        } else {
            Consent::query()->where('guardian_link_id', $link->id)->where('kind', Consent::LESSON_RECORDING)
                ->inForce()->update(['withdrawn_at' => now()]);
        }

        Audit::record($by, $consent ? 'safety.consent.recording_granted' : 'safety.consent.recording_withdrawn', $link,
            ['learner_id' => $link->learner_id]);
    }

    /** A guardian's choices for a teen (a child's are fixed). @param array<string, bool> $settings */
    public function setTeenApprovals(GuardianLink $link, array $settings, User $by): void
    {
        $kinds = config('safety.approvals.kinds');
        $clean = array_intersect_key(array_map('boolval', $settings), array_flip($kinds));

        $link->forceFill(['approval_settings' => $clean])->save();

        Audit::record($by, 'safety.guardian.approval_settings', $link, ['learner_id' => $link->learner_id, 'settings' => $clean]);
    }

    public function hasRecordingConsent(User $learner): bool
    {
        return Consent::query()->inForce()->where('learner_id', $learner->id)->where('kind', Consent::LESSON_RECORDING)
            ->whereIn('guardian_link_id', GuardianLink::query()->active()->where('learner_id', $learner->id)->select('id'))
            ->exists();
    }

    /** @return Collection<int, User> */
    public function guardiansOf(User $learner): Collection
    {
        return User::query()->whereIn('id', GuardianLink::query()->active()->where('learner_id', $learner->id)->select('guardian_id'))
            ->orderBy('id')->get();
    }

    /** @return Collection<int, User> */
    public function childrenOf(User $guardian): Collection
    {
        return User::query()->whereIn('id', GuardianLink::query()->active()->where('guardian_id', $guardian->id)->select('learner_id'))
            ->orderBy('id')->get();
    }

    public function isGuardianOf(User $guardian, User $learner): bool
    {
        return GuardianLink::query()->active()->where('guardian_id', $guardian->id)->where('learner_id', $learner->id)->exists();
    }

    public function activeLink(User $guardian, User $learner): ?GuardianLink
    {
        return GuardianLink::query()->active()->where('guardian_id', $guardian->id)->where('learner_id', $learner->id)->first();
    }

    private function grant(GuardianLink $link, string $kind): Consent
    {
        return Consent::create([
            'learner_id' => $link->learner_id,
            'guardian_id' => $link->guardian_id,
            'guardian_link_id' => $link->id,
            'kind' => $kind,
            'version' => config('safety.guardians.consent_version'),
            'text_hash' => ConsentText::hash($kind),
            'granted_at' => now(),
        ]);
    }

    private function linkHasConsent(GuardianLink $link, string $kind): bool
    {
        return Consent::query()->inForce()->where('guardian_link_id', $link->id)->where('kind', $kind)->exists();
    }
}
