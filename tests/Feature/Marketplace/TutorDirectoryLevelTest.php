<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Contracts\TutorDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/** QA regression: a tutor's scope range covers every level inside it, not only its two ends (rooms "Tutors" tab). */
class TutorDirectoryLevelTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    public function test_a_grade_10_to_12_tutor_is_listed_for_grade_11_and_not_for_grade_9_or_another_curriculum(): void
    {
        $this->setUpWorld();
        [, $profile] = $this->tutorReadyToTeach('Range Tutor');
        $directory = app(TutorDirectory::class);
        $ids = fn (string $curriculum, string $level) => array_column($directory->approved(['level_id' => $this->level($curriculum, $level)->id]), 'id');

        $this->assertContains($profile->id, $ids('za-caps', 'grade-11'), 'inside the range');
        $this->assertContains($profile->id, $ids('za-caps', 'grade-10'), 'range start');
        $this->assertContains($profile->id, $ids('za-caps', 'grade-12'), 'range end');
        $this->assertNotContains($profile->id, $ids('za-caps', 'grade-9'), 'below the range');
        $this->assertNotContains($profile->id, $ids('za-ieb', 'grade-11'), 'same grade in another curriculum');
    }
}
