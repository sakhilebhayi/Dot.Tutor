<?php

namespace Tests\Feature;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Models\TutorSession;
use App\Models\User;
use App\Support\Search\TutorSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-52: the header search is real and scoped. Each scope (learn, guardian, teach, ops) reads only what that person may already open from the
 * screens of that scope: a learner never reaches another learner's lessons, a tutor only their own students, a guardian only the children they
 * are actively linked to, an operator only the groups their role allows. The scope is a request value and is checked, never trusted.
 */
class HeaderSearchTest extends TestCase
{
    use BuildsSafetyState, RefreshDatabase;

    private function suggest(User $user, string $q, ?string $scope = null): array
    {
        $response = $this->actingAs($user)->getJson(route('search.suggest', array_filter(['q' => $q, 'scope' => $scope])))->assertOk();

        return $response->json('groups');
    }

    /** @return list<string> every title in every group */
    private function titles(array $groups): array
    {
        return collect($groups)->flatMap(fn (array $g) => collect($g['rows'])->pluck('label'))->all();
    }

    private function rowsOf(array $groups, string $head): array
    {
        return collect($groups)->firstWhere('head', $head)['rows'] ?? [];
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson(route('search.suggest', ['q' => 'maths']))->assertUnauthorized();
        $this->get(route('search', ['q' => 'maths']))->assertRedirect(route('login'));
    }

    public function test_a_short_or_empty_query_returns_nothing_and_never_errors(): void
    {
        $user = $this->makeUser('Seeker');

        $this->assertSame([], $this->suggest($user, ''));
        $this->assertSame([], $this->suggest($user, 'a'));
        $this->actingAs($user)->getJson(route('search.suggest', ['q' => str_repeat('x', 500)]))->assertStatus(422);
    }

    public function test_a_learner_finds_approved_tutors_but_not_unapproved_ones(): void
    {
        $subject = $this->makeSubject('Quantum Origami');
        $this->makeTutor([], $subject, $this->makeUser('Approved Origami Tutor'));
        $this->makeTutor(['status' => 'pending'], $subject, $this->makeUser('Pending Origami Tutor'));
        $learner = $this->makeUser('Searching Learner');

        $rows = $this->rowsOf($this->suggest($learner, 'origami'), 'Tutors');

        $this->assertSame(['Approved Origami Tutor'], array_column($rows, 'label'));
        $this->assertStringContainsString('/tutors/', $rows[0]['href']);
    }

    public function test_a_learner_sees_only_their_own_lessons(): void
    {
        $subject = $this->makeSubject('Astronomy');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Lyra Tutor'));
        $mine = $this->makeUser('Me Learner');
        $other = $this->makeUser('Other Learner');
        $this->makeSession($tutor, $mine, $subject);
        $theirs = $this->makeSession($tutor, $other, $subject);

        $groups = $this->suggest($mine, 'astronomy');
        $lessons = $this->rowsOf($groups, 'My lessons');

        $this->assertCount(1, $lessons);
        $this->assertStringNotContainsString('/learn/lessons/'.$theirs->id, json_encode($groups));
        $this->assertSame([], $this->rowsOf($this->suggest($this->makeUser('Stranger'), 'astronomy'), 'My lessons'));
    }

    public function test_a_learner_cannot_widen_the_scope_to_teaching_or_operations(): void
    {
        $subject = $this->makeSubject('Geology');
        $tutorUser = $this->makeUser('Gem Tutor');
        $tutor = $this->makeTutor([], $subject, $tutorUser);
        $student = $this->makeUser('Gem Student');
        $this->makeSession($tutor, $student, $subject);
        $learner = $this->makeUser('Nosy Learner');

        foreach (['teach', 'ops', 'guardian', 'nonsense'] as $scope) {
            $groups = $this->suggest($learner, 'gem', $scope);
            $this->assertNotContains('Gem Student', $this->titles($groups), "scope {$scope} must not leak the tutor's student");
            $this->assertSame([], $this->rowsOf($groups, 'Students'));
            $this->assertSame([], $this->rowsOf($groups, 'People'));
        }
    }

    public function test_a_tutor_finds_only_their_own_students_and_lessons(): void
    {
        $subject = $this->makeSubject('Ceramics');
        $tutorUser = $this->makeUser('Pot Tutor');
        $tutor = $this->makeTutor([], $subject, $tutorUser);
        $otherTutor = $this->makeTutor([], $subject, $this->makeUser('Kiln Tutor'));
        $mine = $this->makeUser('Zola Potter');
        $notMine = $this->makeUser('Zola Kilnman');
        $this->makeSession($tutor, $mine, $subject);
        $this->makeSession($otherTutor, $notMine, $subject);

        $groups = $this->suggest($tutorUser, 'zola', 'teach');

        $this->assertSame(['Zola Potter'], array_column($this->rowsOf($groups, 'Students'), 'label'));
        $this->assertStringNotContainsString('Zola Kilnman', json_encode($groups));
        $this->assertCount(1, $this->rowsOf($this->suggest($tutorUser, 'ceramics', 'teach'), 'Lessons and requests'));
    }

    public function test_a_tutor_does_not_see_a_child_still_waiting_for_a_guardian(): void
    {
        $subject = $this->makeSubject('Pottery');
        $tutorUser = $this->makeUser('Wheel Tutor');
        $tutor = $this->makeTutor([], $subject, $tutorUser);
        $child = $this->child('Lindiwe Waiting');
        TutorSession::withoutEvents(fn () => $this->makeSession($tutor, $child, $subject, ['status' => 'awaiting_guardian']));

        $groups = $this->suggest($tutorUser, 'lindiwe', 'teach');

        $this->assertStringNotContainsString('Lindiwe', json_encode($groups), 'a minor with no approval yet is not in the tutor\'s book');
    }

    public function test_a_guardian_sees_only_their_linked_children(): void
    {
        $mine = $this->child('Amahle Linked');
        $other = $this->child('Amahle Stranger');
        $guardian = $this->adult('Parent Guardian');
        $this->linkGuardian($mine, $guardian, false, 9);
        $this->linkGuardian($other, $this->adult('Another Guardian'), false, 9);

        $groups = $this->suggest($guardian, 'amahle', 'guardian');

        $this->assertSame(['Amahle Linked'], array_column($this->rowsOf($groups, 'Children'), 'label'));
        $this->assertStringNotContainsString('Stranger', json_encode($groups));
        // Without a link the guardian scope is not available: it falls back to the learner scope, which finds no children at all.
        $this->assertSame([], $this->rowsOf($this->suggest($this->adult('Not A Guardian'), 'amahle', 'guardian'), 'Children'));
    }

    public function test_the_operator_scope_needs_the_platform_operator_flag_and_each_group_its_permission(): void
    {
        $plain = $this->makeUser('Zanele Plain');
        $admin = $this->makeOperator();
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);
        $editor = $this->makeOperator();
        app(RoleAdministration::class)->setRoles(null, $editor, [OpsRole::AcademicEditor->value]);

        $this->assertSame([], $this->rowsOf($this->suggest($plain, 'zanele', 'ops'), 'People'), 'an ordinary person cannot search people');
        $this->assertContains('Zanele Plain', array_column($this->rowsOf($this->suggest($admin->fresh(), 'zanele', 'ops'), 'People'), 'label'));
        $this->assertSame([], $this->rowsOf($this->suggest($editor->fresh(), 'zanele', 'ops'), 'People'), 'an academic editor has no users.view permission');
    }

    public function test_the_full_results_page_renders_for_each_scope_with_the_no_match_state(): void
    {
        $subject = $this->makeSubject('Cartography');
        $tutorUser = $this->makeUser('Map Tutor');
        $this->makeTutor([], $subject, $tutorUser);
        $operator = $this->makeOperator();

        $this->actingAs($this->makeUser('Reader'))->get(route('search', ['q' => 'cartography']))->assertOk()->assertSee('Map Tutor')->assertSee('Results for');
        $this->actingAs($tutorUser)->get(route('search', ['q' => 'zzzz-nothing', 'scope' => 'teach']))->assertOk()->assertSee('Nothing matches');
        $this->actingAs($operator)->get(route('search', ['q' => 'map', 'scope' => 'ops']))->assertOk()->assertSee('Search');
        $this->actingAs($tutorUser)->get(route('search'))->assertOk()->assertSee('Go to');
    }

    public function test_typed_wildcards_and_quotes_match_themselves_only(): void
    {
        $subject = $this->makeSubject('100% Algebra');
        $this->makeTutor([], $subject, $this->makeUser('Percent Tutor'));
        $learner = $this->makeUser('Wild Learner');

        $this->assertCount(1, $this->rowsOf($this->suggest($learner, '100%'), 'Tutors'));
        $this->assertSame([], $this->rowsOf($this->suggest($learner, '%%'), 'Tutors'), 'a typed percent is not a wildcard');
        $this->assertSame([], $this->suggest($learner, "x' or 1=1 --"), 'quotes are only bound text');
    }

    public function test_the_search_is_throttled(): void
    {
        config(['tutor.throttle.search' => 3]);
        $user = $this->makeUser('Hammer');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->getJson(route('search.suggest', ['q' => 'abc']))->assertOk();
        }
        $this->actingAs($user)->getJson(route('search.suggest', ['q' => 'abc']))->assertStatus(429);
    }

    public function test_scopes_are_listed_from_what_the_person_really_is(): void
    {
        $search = app(TutorSearch::class);
        $plain = $this->makeUser('Only Learner');
        $tutorUser = $this->makeUser('Also Tutor');
        $this->makeTutor([], null, $tutorUser);

        $this->assertSame(['learn'], $search->scopesFor($plain));
        $this->assertSame(['learn', 'teach'], $search->scopesFor($tutorUser));
        $this->assertSame('learn', $search->resolveScope($plain, 'ops'));
        $this->assertSame('teach', $search->resolveScope($tutorUser, 'teach'));
    }

    public function test_each_shell_posts_its_own_scope_and_names_the_suggest_endpoint(): void
    {
        $subject = $this->makeSubject('Header scope');
        $tutorUser = $this->makeUser('Scope Tutor');
        $this->makeTutor([], $subject, $tutorUser);
        $operator = $this->makeOperator();

        foreach ([[$this->makeUser('Scope Learner'), '/learn', 'learn'], [$tutorUser, '/teach', 'teach'], [$operator, '/ops', 'ops']] as [$user, $path, $scope]) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('<input type="hidden" name="scope" value="'.$scope.'">', $html, "{$path}: scope {$scope}");
            $this->assertStringContainsString('data-suggest-url="'.route('search.suggest').'"', $html);
            $this->assertStringContainsString('data-search-pages', $html, 'the empty-box "Go to" block is server rendered');
        }
    }
}
