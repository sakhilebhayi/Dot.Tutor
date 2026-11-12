<?php

namespace App\Domain\Teach\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Teach\Models\Onboarding;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;

/**
 * Where a tutor is in becoming a tutor: each step of the guided onboarding with whether it is done, which steps
 * the application needs before it can be sent, and the honest state of the application itself.
 */
class OnboardingProgress
{
    public const STEPS = ['about', 'photo', 'scope', 'languages', 'qualifications', 'safeguarding', 'availability', 'offering', 'send'];

    public function __construct(private LanguageProfile $languages) {}

    /**
     * @return list<array{key: string, title: string, done: bool, required: bool, summary: string}>
     */
    public function steps(?TutorProfile $profile): array
    {
        $about = $profile !== null && filled($profile->headline) && filled($profile->bio);
        $photo = $profile?->photo_path !== null;
        $scopes = $profile?->teachingScopes()->count() ?? 0;
        $langs = $profile ? count($this->languages->teachingLanguages($profile->user_id)) : 0;
        $quals = $profile?->qualificationRecords()->get() ?? collect();
        $sentQuals = $quals->whereIn('status', [QualificationStatus::Submitted->value, QualificationStatus::Verified->value])->count();
        $onboarding = $profile ? Onboarding::query()->where('tutor_profile_id', $profile->id)->first() : null;
        $windows = $profile?->availabilityWindows()->count() ?? 0;
        $offerings = $profile?->offerings()->where('is_active', true)->count() ?? 0;

        return [
            ['key' => 'about', 'title' => 'About you', 'done' => $about, 'required' => true,
                'summary' => $about ? 'Headline and introduction written.' : 'A headline, an introduction and how you teach.'],
            ['key' => 'photo', 'title' => 'Profile photo', 'done' => $photo, 'required' => false,
                'summary' => $photo ? 'Photo added. Learners see it once you are approved.' : 'Optional. A clear photo of your face helps learners trust you.'],
            ['key' => 'scope', 'title' => 'What you teach', 'done' => $scopes > 0, 'required' => true,
                'summary' => $scopes > 0 ? "{$scopes} ".($scopes === 1 ? 'subject' : 'subjects').' with levels.' : 'Subjects, curriculum and the levels you teach.'],
            ['key' => 'languages', 'title' => 'Teaching languages', 'done' => $langs > 0, 'required' => true,
                'summary' => $langs > 0 ? "{$langs} ".($langs === 1 ? 'language' : 'languages').' declared.' : 'The languages you can explain things in.'],
            ['key' => 'qualifications', 'title' => 'Qualifications and evidence', 'done' => $sentQuals > 0, 'required' => false,
                'summary' => $quals->isEmpty() ? 'Optional to start, needed for a verification level.' : $quals->count().' recorded, '.$sentQuals.' sent for review or verified.'],
            ['key' => 'safeguarding', 'title' => 'Teaching under-18s', 'done' => $onboarding !== null, 'required' => true,
                'summary' => $onboarding === null ? 'Say whether you will teach learners under 18.' : ($onboarding->teaches_minors ? 'You plan to teach under-18s. Screening applies.' : 'Adults only for now.')],
            ['key' => 'availability', 'title' => 'Availability', 'done' => $windows > 0, 'required' => true,
                'summary' => $windows > 0 ? "{$windows} weekly ".($windows === 1 ? 'window' : 'windows').' open.' : 'The weekly hours learners can book.'],
            ['key' => 'offering', 'title' => 'First offering', 'done' => $offerings > 0, 'required' => true,
                'summary' => $offerings > 0 ? "{$offerings} active ".($offerings === 1 ? 'offering' : 'offerings').'.' : 'What you sell, at what price, and what you keep.'],
            ['key' => 'send', 'title' => 'Send for review', 'done' => $profile !== null && $profile->status !== TutorProfileStatus::Draft->value, 'required' => true,
                'summary' => $this->stateSentence($profile)],
        ];
    }

    /** @return list<string> titles of the required steps that are not done (excluding "send") */
    public function missing(?TutorProfile $profile): array
    {
        return collect($this->steps($profile))
            ->filter(fn ($s) => $s['required'] && ! $s['done'] && $s['key'] !== 'send')
            ->pluck('title')->values()->all();
    }

    /**
     * The application's state in the words the brief asks for.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public function state(?TutorProfile $profile): array
    {
        return match ($profile?->status) {
            null => ['key' => 'none', 'label' => 'Not started', 'tone' => 'neutral'],
            TutorProfileStatus::Draft->value => ['key' => 'draft', 'label' => 'Draft, not sent', 'tone' => 'neutral'],
            TutorProfileStatus::Pending->value => ['key' => 'review', 'label' => 'Sent, under review', 'tone' => 'info'],
            TutorProfileStatus::Approved->value => ['key' => 'approved', 'label' => 'Approved', 'tone' => 'success'],
            TutorProfileStatus::Rejected->value => ['key' => 'rejected', 'label' => 'Not approved', 'tone' => 'danger'],
            TutorProfileStatus::Suspended->value => ['key' => 'suspended', 'label' => 'Suspended', 'tone' => 'warning'],
            default => ['key' => 'unknown', 'label' => ucfirst((string) $profile?->status), 'tone' => 'neutral'],
        };
    }

    private function stateSentence(?TutorProfile $profile): string
    {
        return match ($this->state($profile)['key']) {
            'none', 'draft' => 'Send your profile when the required steps are done.',
            'review' => 'Sent. An operator is reviewing your profile.',
            'approved' => 'Approved. Learners can find and book you.',
            'rejected' => 'Not approved. Read the reason, change your profile and send it again.',
            'suspended' => 'Suspended. Read the reason before sending it again.',
            default => '',
        };
    }
}
