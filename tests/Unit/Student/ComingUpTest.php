<?php

namespace Tests\Unit\Student;

use App\Domain\Student\Support\ComingUp;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/** R-51: the learner home's one "Coming up" list merges five collections into rows of one shape. */
class ComingUpTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function work(string $title, ?CarbonImmutable $due, bool $overdue = false): array
    {
        return ['assignment' => (object) ['assessment_id' => 7], 'title' => $title, 'due_at' => $due, 'overdue' => $overdue];
    }

    public function test_every_row_has_the_same_shape_and_the_kinds_are_labelled(): void
    {
        $subject = $this->makeSubject('Physics');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Tutor T'));
        $student = $this->makeUser('Learner L');
        $lesson = $this->makeSession($tutor, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(2)]);
        $lesson->load(['tutorProfile.user', 'subject', 'offering']);

        $rows = ComingUp::rows(collect([$lesson]), collect(), [$this->work('Quiz one', now()->addDays(3)->toImmutable())], [], collect());

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['kind', 'title', 'meta', 'href', 'tag', 'action', 'at', 'needs_you'], array_keys($row));
        }
        $this->assertSame('lesson', $rows[0]['kind']);
        $this->assertStringStartsWith('Physics with ', $rows[0]['title']);
        $this->assertSame(route('learn.lessons.show', $lesson), $rows[0]['href']);
        $this->assertSame('Confirmed', $rows[0]['tag']['text']);
        $this->assertSame('work', $rows[1]['kind']);
        $this->assertSame(route('learning.assessments.take', 7), $rows[1]['href']);
        $this->assertSame('Work due', $rows[1]['tag']['text']);
    }

    public function test_what_needs_the_learner_comes_first_then_dated_rows_then_undated(): void
    {
        $subject = $this->makeSubject('Maths');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Tutor M'));
        $student = $this->makeUser('Learner M');
        $soon = $this->makeSession($tutor, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDay()]);
        $later = $this->makeSession($tutor, $student, $subject, ['status' => 'pending', 'starts_at' => now()->addDays(5)]);
        $pay = $this->makeSession($tutor, $student, $subject, ['status' => 'awaiting_payment', 'starts_at' => now()->addDays(9)]);
        $lessons = collect([$soon, $later, $pay])->each->load(['tutorProfile.user', 'subject', 'offering']);

        $rows = ComingUp::rows($lessons, collect(), [$this->work('Overdue test', now()->subDay()->toImmutable(), true)], [], collect());

        $this->assertSame(['work', 'lesson', 'lesson', 'lesson'], array_column($rows, 'kind'));
        // The overdue work and the lesson to pay for need the learner, so they lead; the soonest of those is the overdue one.
        $this->assertTrue($rows[0]['needs_you']);
        $this->assertTrue($rows[1]['needs_you']);
        $this->assertSame('Pay to confirm', $rows[1]['tag']['text']);
        $this->assertFalse($rows[2]['needs_you']);
        $this->assertSame(route('learn.lessons.show', $soon), $rows[2]['href']);
        $this->assertSame(route('learn.lessons.show', $later), $rows[3]['href']);
    }

    public function test_the_list_is_capped_and_an_empty_input_gives_no_rows(): void
    {
        $this->assertSame([], ComingUp::rows(collect(), collect(), [], [], collect()));

        $subject = $this->makeSubject('Chem');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Tutor C'));
        $student = $this->makeUser('Learner C');
        /** @var Collection<int, TutorSession> $lessons */
        $lessons = collect(range(1, 5))->map(fn ($i) => $this->makeSession($tutor, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays($i)])->load(['tutorProfile.user', 'subject', 'offering']));

        $this->assertCount(3, ComingUp::rows($lessons, collect(), [], [], collect(), null, 3));
        $this->assertCount(5, ComingUp::rows($lessons, collect(), [], [], collect()));
    }

    public function test_the_last_lesson_sits_after_the_cap_with_a_way_back(): void
    {
        $subject = $this->makeSubject('Bio');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Tutor B'));
        $student = $this->makeUser('Learner B');
        $done = $this->makeSession($tutor, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);
        $done->load(['tutorProfile.user', 'subject', 'offering']);

        $rows = ComingUp::rows(collect(), collect(), [], [], collect(), ['lesson' => $done, 'summary' => null], 0);

        $this->assertCount(1, $rows);
        $this->assertSame('last', $rows[0]['kind']);
        $this->assertStringStartsWith('Your last lesson: Bio with ', $rows[0]['title']);
        $this->assertNull($rows[0]['at']);
        $this->assertNotNull($rows[0]['action']);
    }
}
