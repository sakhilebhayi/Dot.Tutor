<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Support\MinorUnits;
use App\Domain\Safety\Services\ContactPrivacy;
use App\Models\SessionRating;
use App\Models\TutorProfile;

/**
 * The public shape of a tutor (brief section 18: storefront data) and the
 * public label rules (section 16):
 *  - the verification label comes only from an operator-assigned level;
 *    without one the tutor is "Not yet verified";
 *  - each qualification says whether Dot.Tutor verified it; rejected records
 *    are not shown publicly; the legacy free-text qualifications are shown as
 *    self-declared, never as verified;
 *  - review counts are shown as they are (published reviews only), with no
 *    average below one review.
 */
class TutorPresenter
{
    /**
     * One status for the whole profile (fix round D-2302): when a qualification record was verified but no level has
     * been set yet, the profile says so instead of "Not yet verified" above a "Verified by Dot.Tutor" record.
     *
     * @return array{level: ?string, state: string, label: string, description: string, assigned_at: ?string}
     */
    public function verification(TutorProfile $profile): array
    {
        $level = $profile->verificationLevel();

        if ($level === null && self::hasVerifiedRecord($profile)) {
            return [
                'level' => null,
                'state' => 'checked',
                'label' => self::CHECKED_LABEL,
                'description' => self::CHECKED_DETAIL,
                'assigned_at' => null,
            ];
        }

        return [
            'level' => $level?->value,
            'state' => $level ? 'verified' : 'unverified',
            'label' => $level?->label() ?? 'Not yet verified',
            'description' => $level?->description() ?? 'Dot.Tutor has not reviewed evidence for this tutor yet.',
            'assigned_at' => $level ? $profile->verification_level_set_at?->toIso8601String() : null,
        ];
    }

    public const CHECKED_LABEL = 'Qualification checked, level pending';

    public const CHECKED_DETAIL = 'Dot.Tutor verified a qualification record; the tutor\'s verification level has not been set yet.';

    public static function hasVerifiedRecord(TutorProfile $profile): bool
    {
        return $profile->relationLoaded('qualificationRecords')
            ? $profile->qualificationRecords->contains(fn ($q) => $q->status === QualificationStatus::Verified->value)
            : $profile->qualificationRecords()->where('status', QualificationStatus::Verified->value)->exists();
    }

    /** @return array<string, mixed> */
    public function qualification(TutorQualification $q, bool $owner = false): array
    {
        $status = $q->statusEnum();

        $public = [
            'id' => $q->id,
            'kind' => $q->kind,
            'kind_label' => $q->kindEnum()->label(),
            'title' => $q->title,
            'institution' => $q->institution,
            'year_awarded' => $q->year_awarded,
            'status' => $q->status,
            'status_label' => $status->publicLabel(),
            'verified' => $status === QualificationStatus::Verified,
            'verified_at' => $status === QualificationStatus::Verified ? $q->reviewed_at?->toIso8601String() : null,
        ];

        if (! $owner) {
            return $public;
        }

        return $public + [
            'review_note' => $q->review_note,
            'evidence' => $q->evidence->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->original_name, 'mime' => $e->mime, 'size_bytes' => $e->size_bytes,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function offering(TutorOffering $o): array
    {
        return [
            'id' => $o->id,
            'tutor_profile_id' => $o->tutor_profile_id,
            'type' => $o->type,
            'type_label' => $o->typeEnum()->label(),
            'title' => $o->title,
            'description' => $o->description,
            'price_minor' => $o->price_minor,
            'currency' => $o->currency,
            'price_display' => $o->price_minor === 0 ? 'Free' : MinorUnits::format($o->price_minor, $o->currency),
            'duration_minutes' => $o->duration_minutes,
            'format' => $o->format,
            'area' => $o->area,
            'capacity' => $o->capacity,
            'package_lessons' => $o->package_lessons,
            'package_valid_days' => $o->package_valid_days,
            'recurring_weeks' => $o->recurring_weeks,
            'subjects' => $o->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'level_ids' => $o->level_ids ?? [],
            'cancellation' => [
                'notice_hours' => $o->cancellation_notice_hours,
                'late_refund_percent' => $o->late_cancellation_refund_percent,
                'summary' => $this->cancellationSummary($o),
            ],
            'is_active' => $o->is_active,
        ];
    }

    public function cancellationSummary(TutorOffering $o): string
    {
        if ($o->cancellation_notice_hours === 0) {
            return 'Free cancellation up to the start time.';
        }

        $late = $o->late_cancellation_refund_percent === 0
            ? 'later cancellations are not refunded'
            : "later cancellations get {$o->late_cancellation_refund_percent}% back";

        return "Free cancellation up to {$o->cancellation_notice_hours} hours before the start; {$late}.";
    }

    /** @return array{count: int, average: ?float, distribution: array<int, int>} */
    public function reviewSummary(TutorProfile $profile): array
    {
        $rows = SessionRating::query()
            ->join('tutor_sessions', 'tutor_sessions.id', '=', 'session_ratings.session_id')
            ->where('tutor_sessions.tutor_profile_id', $profile->id)
            ->where('session_ratings.status', ReviewStatus::Published->value)
            ->groupBy('session_ratings.rating')
            ->selectRaw('session_ratings.rating as r, COUNT(*) as n')
            ->pluck('n', 'r');

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $distribution[$stars] = (int) ($rows[$stars] ?? 0);
        }
        $count = array_sum($distribution);
        $average = $count === 0 ? null : round(array_sum(array_map(fn ($s, $n) => $s * $n, array_keys($distribution), $distribution)) / $count, 2);

        return ['count' => $count, 'average' => $average, 'distribution' => $distribution];
    }

    /**
     * The storefront: everything a learner needs to decide, in one payload.
     *
     * @return array<string, mixed>
     */
    public function storefront(TutorProfile $profile): array
    {
        $profile->loadMissing(['user', 'subjects', 'teachingScopes.subject', 'qualificationRecords.evidence', 'offerings.subjects']);
        $academic = app(AcademicScopePort::class);
        $languages = app(LanguageProfilePort::class);
        // Free text a tutor wrote is shown with contact details hidden whenever a learner under 18 (or of unknown
        // age) is reading, through safety's view_contact rule (R-47 security S-08).
        $text = fn (?string $value) => blank($value) || $profile->user === null ? $value
            : app(ContactPrivacy::class)->profileText(auth()->user(), $profile->user, $value)['text'];

        return [
            'id' => $profile->id,
            'handle' => $profile->public_handle,
            'name' => $profile->displayName(),
            'headline' => $text($profile->headline),
            'introduction' => $text($profile->bio),
            'teaching_approach' => $text($profile->teaching_approach),
            'experience' => [
                'summary' => $text($profile->experience_summary),
                'years' => $profile->years_experience,
                'sessions_completed' => (int) $profile->total_sessions,
            ],
            'photo_url' => $profile->photo_path ? route('marketplace.tutors.photo', $profile) : null,
            'verification' => $this->verification($profile),
            'qualifications' => $profile->qualificationRecords
                ->reject(fn (TutorQualification $q) => $q->status === QualificationStatus::Rejected->value)
                ->map(fn (TutorQualification $q) => $this->qualification($q))
                ->values()->all(),
            'self_declared_qualifications' => filled($profile->qualifications) ? [
                'text' => $text($profile->qualifications),
                'label' => QualificationStatus::Unverified->publicLabel(),
            ] : null,
            'teaching_scope' => $profile->teachingScopes->map(fn ($s) => [
                'subject' => ['id' => $s->subject_id, 'name' => $s->subject?->name],
                'curriculum' => $s->curriculum_id ? ['id' => $s->curriculum_id, 'name' => $academic->curriculumName($s->curriculum_id)] : null,
                'level_min' => $s->level_min_id ? ['id' => $s->level_min_id, 'name' => $academic->levelName($s->level_min_id)] : null,
                'level_max' => $s->level_max_id ? ['id' => $s->level_max_id, 'name' => $academic->levelName($s->level_max_id)] : null,
            ])->values()->all(),
            'subjects' => $profile->subjects->where('is_active', true)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'languages' => $languages->isAvailable() && $profile->user ? $languages->teachingLanguages($profile->user) : null,
            'formats' => [
                'online' => (bool) $profile->teaches_online,
                'in_person' => (bool) $profile->teaches_in_person,
                'in_person_area' => $profile->teaches_in_person ? $profile->in_person_area : null,
                'group_size_min' => $profile->group_size_min,
                'group_size_max' => $profile->group_size_max,
            ],
            'pricing' => [
                'hourly_rate_minor' => $profile->hourly_rate_minor,
                'currency' => $profile->currency,
                'hourly_rate_display' => $profile->hourly_rate_minor !== null ? MinorUnits::format((int) $profile->hourly_rate_minor, $profile->currency).' per hour' : null,
            ],
            // Offering titles and descriptions are tutor free text too (R-47 polish D-2003: the storefront is public).
            'offerings' => $profile->offerings->where('is_active', true)->map(fn ($o) => ['title' => $text($o->title), 'description' => $text($o->description)] + $this->offering($o))->values()->all(),
            'reviews' => $this->reviewSummary($profile),
            'availability_url' => route('marketplace.api.tutors.availability', $profile),
            'timezone' => $profile->scheduleTimezone(),
            // Owned by other domains; not connected on this branch (null means "not available", not "none").
            'courses' => null,
            'study_materials' => null,
            'share_url' => route('marketplace.tutors.show', $profile),
        ];
    }
}
