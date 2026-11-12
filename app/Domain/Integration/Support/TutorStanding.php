<?php

namespace App\Domain\Integration\Support;

use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Safety\Services\Screening;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;

/**
 * One truthful summary of where a tutor stands (D-2210), read from the records that decide it and worded the same in
 * the tutor workspace and on the operator screens (x-td.tutor-standing). Three separate facts, because they are
 * separate decisions made by different people:
 *
 *  - listed: the profile review (an operator approves, rejects or suspends the profile);
 *  - qualification: the verification level, set only from a verified qualification record (never from the profile);
 *  - minors: the safeguarding screening record (cleared and current for every required kind).
 *
 * Nothing here is inferred from what the tutor wrote about themselves.
 */
class TutorStanding
{
    public function __construct(private Screening $screening) {}

    /**
     * @return list<array{key: string, ok: bool|null, text: string, detail: string}>
     *                                                                               ok: true done, false not done, null waiting
     */
    public function facts(TutorProfile $profile): array
    {
        $status = (string) $profile->status;
        $listed = match ($status) {
            TutorProfileStatus::Approved->value => [true, 'Listed', 'Learners can find and book this tutor.'],
            TutorProfileStatus::Pending->value => [null, 'Not listed yet', 'The profile is waiting for an operator\'s review.'],
            TutorProfileStatus::Rejected->value => [false, 'Not listed', 'The profile was not approved.'],
            TutorProfileStatus::Suspended->value => [false, 'Not listed', 'The tutor is suspended.'],
            default => [false, 'Not listed', 'The profile is a draft and has not been sent for review.'],
        };

        $level = $profile->verificationLevel();
        $reviewing = $level === null && $profile->qualificationRecords()->where('status', 'submitted')->exists();
        $checked = $level === null && ! $reviewing && TutorPresenter::hasVerifiedRecord($profile);
        $qualification = match (true) {
            $level !== null => [true, 'Qualification verified', $level->label().', from a reviewed record.'],
            // A verified record without a level (fix round D-2302): the same words as the public profile.
            $checked => [null, TutorPresenter::CHECKED_LABEL, TutorPresenter::CHECKED_DETAIL],
            $reviewing => [null, 'Qualification in review', 'Evidence was sent and is waiting for a reviewer.'],
            default => [false, 'Qualification not verified', 'Anything the profile says about qualifications is self-declared.'],
        };

        $cleared = $profile->user !== null && $this->screening->isClearedForMinors($profile->user);
        $minors = $cleared
            ? [true, 'Cleared to teach under-18s', 'Screening is recorded and current.']
            : [false, 'Not cleared to teach under-18s', 'No current screening record, so learners under 18 cannot book.'];

        return [
            ['key' => 'listed', 'ok' => $listed[0], 'text' => $listed[1], 'detail' => $listed[2]],
            ['key' => 'qualification', 'ok' => $qualification[0], 'text' => $qualification[1], 'detail' => $qualification[2]],
            ['key' => 'minors', 'ok' => $minors[0], 'text' => $minors[1], 'detail' => $minors[2]],
        ];
    }
}
