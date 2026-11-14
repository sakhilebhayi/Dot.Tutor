<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * R-51: the tutor's "Find a student" box is now the shared compact filter. What it searches, and whose students it may
 * search, did not change: the same `q` parameter on the same route, a name match, scoped to the signed-in tutor.
 */
class DotUiFilterResultsTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    public function test_the_students_filter_is_the_shared_filter_and_searches_only_this_tutors_students(): void
    {
        $this->setUpWorld();
        [$alice, $aliceProfile, $aliceOffering] = $this->tutorReadyToTeach('Alice Dlamini', 30000);
        [, $bobProfile, $bobOffering] = $this->tutorReadyToTeach('Bob Naidoo', 20000);
        foreach ([['Sipho Learner', $aliceOffering, $aliceProfile, 3], ['Anele Ngcobo', $aliceOffering, $aliceProfile, 4], ['Zodwa Learner', $bobOffering, $bobProfile, 3]] as [$name, $offering, $profile, $days]) {
            $learner = $this->person($name);
            $this->learnerProfile($learner, 20);
            $this->requestLesson($learner, $offering, $profile, $days)->assertCreated();
        }

        $page = $this->actingAs($alice)->get(route('teach.students', ['q' => 'Sipho']))->assertOk();
        $html = $page->getContent();

        $page->assertSee('Sipho Learner')->assertDontSee('Anele Ngcobo')->assertDontSee('Zodwa Learner');
        $this->assertSame(1, preg_match('/<form method="GET" action="([^"]+)"[^>]*role="search"/', $html, $form));
        $this->assertSame(route('teach.students'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*\bname="q"[^>]*\bvalue="Sipho"|<input[^>]*\bname="q"[^>]*\bvalue="Sipho"[^>]*\bclass="dsr-filter"/', $html);
        $this->assertStringContainsString('<label class="td-label" for="q">', $html, 'the visible label still points at the input');

        // Another tutor's student is not found by name, and the page does not say it exists.
        $this->actingAs($alice)->get(route('teach.students', ['q' => 'Zodwa']))->assertOk()->assertDontSee('Zodwa Learner')->assertSee('No students match');

        // Empty search lists this tutor's own students only.
        $this->actingAs($alice)->get(route('teach.students'))->assertOk()->assertSee('Sipho Learner')->assertSee('Anele Ngcobo')->assertDontSee('Zodwa Learner');
    }

    public function test_the_filter_pages_still_need_a_signed_in_person(): void
    {
        $this->get(route('teach.students', ['q' => 'Sipho']))->assertRedirect('/login');
        $this->get(route('learning.courses.index', ['q' => 'x']))->assertRedirect('/login');
        $this->get(route('learning.library.index', ['q' => 'x']))->assertRedirect('/login');
        $this->get(route('ops.users', ['q' => 'x']))->assertRedirect('/login');
    }
}
