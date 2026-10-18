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
            [$student, route('dashboard')],
            [$student, route('tutors.browse')],
            [$student, route('tutors.show', $tutor)],
            [$student, route('sessions.show', $session)],
            [$student, route('tutor.profile.edit')],
            [$student, route('profile.show')],
            [$tutorUser, route('tutor.availability.index')],
            [$this->makeOperator(), route('operator.tutor-profiles.index')],
            [$this->makeOperator(), route('operator.subjects.index')],
        ] as [$user, $url]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url));
        }
    }

    public function test_the_legacy_jetstream_bar_is_wrapped_so_phones_can_hide_it(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeUser())->get(route('dashboard')));

        $this->assertSame(1, $xpath->query('//div[contains(@class, "shell-legacy-nav")]/nav')->length);
    }

    public function test_the_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $subject = $this->makeSubject('Scroll region Statistics');
        $student = $this->makeUser('Scroll Student');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Scroll Tutor'));
        $this->makeSession($tutor, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($student)->get(route('dashboard'))), 'Recent sessions');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($tutor->user)->get(route('tutor.availability.index'))), 'Weekly windows');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('operator.subjects.index'))), 'Subjects');
    }

    public function test_the_drawer_reaches_browse_teaching_and_operator_pages(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeUser())->get(route('dashboard')));
        foreach (['dashboard', 'tutors.browse', 'tutor.profile.edit', 'profile.show'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('operator.subjects.index').'"]')->length);

        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('dashboard')));
        foreach (['operator.tutor-profiles.index', 'operator.subjects.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
    }

    public function test_the_drawer_footer_has_sign_out_and_a_team_switcher(): void
    {
        $user = $this->makeUser('Switcher Student');
        $second = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Matric Maths Study Group']);

        $xpath = $this->assertResponsiveShell($this->actingAs($user)->get(route('dashboard')));

        $footer = '//aside[@id="app-sidebar"]//div[contains(@class, "sidebar-footer")]';
        $this->assertSame(1, $xpath->query($footer.'//form[@action="'.route('logout').'"]//button[@aria-label="Sign out"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//details[contains(@class, "team-switch")]/summary')->length);
        $this->assertSame(1, $xpath->query($footer.'//a[@href="'.route('teams.show', $user->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($footer.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
    }
}
