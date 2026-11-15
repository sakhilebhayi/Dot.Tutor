<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsResponsiveShell;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class ResponsiveShellTest extends TestCase
{
    use AssertsResponsiveShell, BuildsMarketplace, RefreshDatabase;

    public function test_every_signed_in_page_carries_the_drawer_hooks(): void
    {
        $subject = $this->makeSubject('Drawer hooks Calculus');
        $student = $this->makeUser('Hooked Student');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Hooked Tutor'));
        $this->openWeek($tutor);
        $session = $this->makeSession($tutor, $student, $subject);
        $tutorUser = $tutor->user;

        foreach ([
            [$student, route('learn.home')],
            [$student, route('tutors.browse')],
            [$student, route('tutors.show', $tutor)],
            [$student, route('sessions.show', $session)],
            [$student, route('teach.profile')],
            [$student, route('profile.show')],
            [$tutorUser, route('teach.calendar')],
            [$this->makeOperator(), route('operator.tutor-profiles.index')],
            [$this->makeOperator(), route('operator.subjects.index')],
        ] as [$user, $url]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url));
        }
    }

    public function test_the_hidden_legacy_jetstream_bar_is_gone(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeUser())->get(route('profile.show')));

        $this->assertSame(0, $xpath->query('//div[contains(@class, "shell-legacy-nav")]')->length, 'the dead hidden Livewire navigation is no longer mounted on every legacy page');
    }

    public function test_the_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $subject = $this->makeSubject('Scroll region Statistics');
        $student = $this->makeUser('Scroll Student');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Scroll Tutor'));
        $this->makeSession($tutor, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('operator.subjects.index'))), 'Subjects');
    }

    public function test_the_drawer_reaches_browse_teaching_and_operator_pages(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeUser())->get(route('profile.show')));
        foreach (['dashboard', 'tutors.browse', 'teach.profile', 'profile.show'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('operator.subjects.index').'"]')->length);

        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('profile.show')));
        foreach (['operator.tutor-profiles.index', 'operator.subjects.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
    }

    public function test_the_account_menu_has_sign_out_and_a_team_switch(): void
    {
        // R-47 polish D-2023 (moved into the shared header's account menu by R-52): the switch is for a tutor (or operator) who uses more
        // than one team; a learner with the same two teams sees only sign-out.
        $learner = $this->makeUser('Plain Learner');
        Team::factory()->create(['user_id' => $learner->id, 'personal_team' => false, 'name' => 'Learner Second Team']);
        $xpath = $this->assertResponsiveShell($this->actingAs($learner)->get(route('profile.show')));
        $this->assertSame(0, $xpath->query('//header//form[@action="'.route('current-team.update').'"]')->length);
        $this->assertSame(1, $xpath->query('//header//form[contains(@class, "dh-signout")][@action="'.route('logout').'"]//button')->length);

        $user = $this->makeUser('Switcher Tutor');
        $this->makeTutor([], null, $user);
        $second = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Matric Maths Study Group']);

        $xpath = $this->assertResponsiveShell($this->actingAs($user)->get(route('profile.show')));

        $menu = '//header//div[contains(@class, "dh-account")]';
        $this->assertSame(1, $xpath->query($menu.'//form[contains(@class, "dh-signout")][@action="'.route('logout').'"]//button')->length);
        $this->assertSame(1, $xpath->query($menu.'//a[@href="'.route('teams.show', $user->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($menu.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($menu.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
    }
}
