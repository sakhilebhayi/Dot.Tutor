<?php

namespace Tests\Feature\Community;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Rooms;
use App\Domain\Learning\Models\LibraryResource;
use App\Models\TutorProfile;
use App\Models\User;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\Feature\Safety\BuildsSafetyState;

/**
 * Community fixtures on the real academic reference data (the same rows academic:sync inserts) and the real
 * safety state builders: people of a known age, guardians linked through the real consent flow, screenings.
 */
trait BuildsCommunity
{
    use BuildsSafetyState;
    use SeedsAcademicStructure;

    protected function grade(string $code = 'grade-10'): AcademicLevel
    {
        return $this->level('za-caps', $code);
    }

    protected function offering(string $subject = 'mathematics', string $level = 'grade-10'): SubjectOffering
    {
        return SubjectOffering::query()->where('academic_level_id', $this->grade($level)->id)
            ->where('subject_id', $this->subject($subject)->id)->firstOrFail();
    }

    protected function subjectRoom(string $audience = Room::UNDER_18, string $subject = 'mathematics', string $level = 'grade-10'): Room
    {
        return app(Rooms::class)->subjectRoom($this->offering($subject, $level), $audience);
    }

    /** A teen whose guardian has consented (so the account is not limited). */
    protected function consentedTeen(string $name = 'Teen Learner'): User
    {
        $teen = $this->teen($name);
        $this->linkGuardian($teen, null, false, 15);

        return $teen;
    }

    protected function consentedChild(string $name = 'Child Learner'): User
    {
        $child = $this->child($name);
        $this->linkGuardian($child, null, false, 9);

        return $child;
    }

    protected function approvedTutor(string $name = 'Room Tutor', bool $screened = false): User
    {
        $user = $this->adult($name);
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 200, 'status' => 'approved', 'timezone' => 'Africa/Johannesburg']);
        if ($screened) {
            $this->screen($user);
        }

        return $user;
    }

    /** @param list<string> $subjects */
    protected function profileAt(User $learner, string $level, array $subjects = []): LearnerProfile
    {
        $levelModel = $this->grade($level);
        $profile = new LearnerProfile([
            'curriculum_id' => $levelModel->curriculum_id,
            'academic_level_id' => $levelModel->id,
            'onboarded_at' => now(),
        ]);
        $profile->forceFill(['user_id' => $learner->id])->save();
        foreach ($subjects as $slug) {
            $profile->subjects()->attach($this->subject($slug)->id, ['curriculum_subject_level_id' => $this->offering($slug, $level)->id]);
        }

        return $profile;
    }

    protected function libraryResource(User $owner, string $status = 'published', string $subject = 'mathematics'): LibraryResource
    {
        return LibraryResource::create([
            'owner_id' => $owner->id,
            'title' => 'Quadratic equations summary',
            'type' => 'notes',
            'subject_id' => $this->subject($subject)->id,
            'level_id' => $this->grade()->id,
            'curriculum_id' => $this->grade()->curriculum_id,
            'language' => 'en',
            'ownership' => 'own_work',
            'licence' => 'cc_by',
            'is_free' => true,
            'status' => $status,
            'quality_tier' => 'community',
            'path' => 'library/test.pdf',
            'mime' => 'application/pdf',
            'size' => 1000,
            'sha256' => str_repeat('a', 64),
            'original_name' => 'summary.pdf',
        ]);
    }

    /** @return array<string, mixed> */
    protected function question(string $title = 'How do I factorise x squared minus 9?', string $body = 'I keep getting stuck on the difference of squares.'): array
    {
        return ['kind' => 'question', 'title' => $title, 'body' => $body, 'original_locale' => 'en'];
    }
}
