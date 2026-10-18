<?php

namespace App\Notifications;

use App\Models\TutorProfile;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant what an operator decided, and why. Database channel
 * only: it surfaces on their dashboard. (Mail is not wired for this app.)
 */
class TutorProfileDecision extends Notification
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const REINSTATED = 'reinstated';

    public function __construct(
        public readonly TutorProfile $profile,
        public readonly string $decision,
        public readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'tutor_profile_id' => $this->profile->id,
            'message' => match ($this->decision) {
                self::APPROVED => 'Your tutor profile was approved. You are now listed and can be booked.',
                self::REJECTED => 'Your tutor application was not approved. Edit it and re-apply.',
                self::SUSPENDED => 'Your tutor profile was suspended. Your pending requests were cancelled.',
                self::REINSTATED => 'Your tutor profile was reinstated. You are listed again.',
                default => 'Your tutor profile status changed.',
            },
            'url' => route('tutor.profile.edit'),
        ];
    }
}
