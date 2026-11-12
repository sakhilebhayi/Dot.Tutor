<?php

namespace Tests\Feature\Teach;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Support\PlainWords;
use App\Domain\Teach\Services\AcademicOptions;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\ChartScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * The polish wave for the tutor workspace and the operator screens (polish-teach, D-2200 onward): one tutor shell,
 * one standing summary in both views, stacked phone tables, the subject and level pickers, plain operator words and
 * integer chart ticks.
 */
class PolishTeachTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Polish Admin']);
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);

        return $admin->fresh();
    }

    public function test_the_tutor_and_the_operator_read_the_same_standing_and_the_approval_is_a_recorded_decision(): void
    {
        $this->setUpWorld();
        $user = $this->person('Pending Tutor');
        $profile = TutorProfile::query()->create([
            'user_id' => $user->id, 'headline' => 'Maths', 'bio' => 'Bio', 'hourly_rate' => 0, 'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg', 'status' => 'pending',
        ]);
        $admin = $this->admin();

        $facts = ['Not listed yet', 'Qualification not verified', 'Not cleared to teach under-18s'];
        $this->actingAs($user)->get(route('teach.profile'))->assertOk()->assertSeeInOrder($facts);
        $this->actingAs($admin)->get(route('ops.verification.tutor', $profile))->assertOk()->assertSeeInOrder($facts)
            ->assertSee('Reason (required to reject)');

        $this->actingAs($admin)->post(route('ops.verification.standing', $profile), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('ops.verification.standing', $profile), ['action' => 'suspend', 'reason' => 'Two missed lessons in a week.'])->assertSessionHasNoErrors();

        // Both profile decisions are in the decisions panel with the operator's reason.
        $this->actingAs($admin)->get(route('ops.verification.tutor', $profile))->assertOk()
            ->assertSee('approved')->assertSee('suspended')->assertSee('Two missed lessons in a week.');
        $this->actingAs($user)->get(route('teach.storefront'))->assertOk()->assertSee('Not listed')->assertDontSee('self-declared, not verified');
    }

    public function test_tutor_tables_and_ops_tables_stack_on_phones_with_the_key_figure_marked(): void
    {
        $this->setUpWorld();
        [$tutor] = $this->tutorReadyToTeach('Stack Tutor', 30000);
        $this->actingAs($tutor)->post(route('teach.offerings.store'), [
            'type' => 'one_to_one', 'title' => 'Grade 11 Maths', 'subject_ids' => [$this->subject('mathematics')->id],
            'duration_minutes' => 60, 'format' => 'online', 'price' => '300', 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50,
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($tutor)->get(route('teach.offerings'))->assertOk()->getContent();
        $this->assertStringContainsString('data-td-stack', $html);
        $this->assertMatchesRegularExpression('/<th scope="col" class="is-num" data-stack="key">You keep<\/th>/', $html);
        $this->assertStringContainsString('data-stack="action"', $html);

        $this->actingAs($this->admin())->get(route('ops.safeguarding'))->assertOk();
    }

    public function test_the_offering_form_uses_touch_friendly_level_checkboxes_and_one_line_cancellation_terms(): void
    {
        $this->setUpWorld();
        [$tutor] = $this->tutorReadyToTeach('Levels Tutor', 30000);

        $form = $this->actingAs($tutor)->get(route('teach.offerings.create', ['type' => 'one_to_one']))->assertOk();
        $form->assertDontSee('Hold Ctrl or Command')->assertDontSee('multiple size=', false)
            ->assertSee('type="checkbox" id="level-'.$this->level('za-caps', 'grade-11')->id.'" name="level_ids[]"', false)
            ->assertSee('id="cancellation-note"', false);

        $this->actingAs($tutor)->post(route('teach.offerings.store'), [
            'type' => 'one_to_one', 'title' => 'Grade 11 only', 'subject_ids' => [$this->subject('mathematics')->id],
            'level_ids' => [$this->level('za-caps', 'grade-11')->id],
            'duration_minutes' => 60, 'format' => 'online', 'price' => '300', 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tutor_offerings', ['title' => 'Grade 11 only']);
    }

    public function test_the_subject_picker_groups_by_area_and_searches_other_names(): void
    {
        $this->setUpWorld();
        $choices = collect(app(AcademicOptions::class)->subjectChoices());
        $maths = $choices->firstWhere('label', 'Mathematics');

        $this->assertSame('Mathematics', $maths['group']);
        $this->assertStringContainsString('wiskunde', $maths['search']);
        $this->assertFalse($choices->contains(fn ($c) => str_contains($c['label'], 'Primary school')), 'the name is never run together with a phase');

        $user = $this->person('Scope Tutor');
        $this->actingAs($user)->post(route('teach.profile.start'), [
            'headline' => 'Maths', 'introduction' => str_repeat('I teach mathematics step by step. ', 3), 'teaches_online' => '1', 'timezone' => 'Africa/Johannesburg',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user)->get(route('teach.profile', ['step' => 'scope']))->assertOk()
            ->assertSee('data-td-combobox', false)->assertSee('data-td-combobox-group="Mathematics"', false)
            ->assertSee('<optgroup label="Mathematics">', false);
    }

    public function test_a_tutor_without_a_profile_rate_is_never_booked_at_zero_through_the_old_hourly_form(): void
    {
        $this->setUpWorld();
        $subject = $this->subject('mathematics');
        $tutor = $this->makeTutor(['status' => 'approved'], $subject);
        TutorProfile::query()->whereKey($tutor->id)->update(['hourly_rate_minor' => null, 'hourly_rate' => 0]);
        $this->openWeek($tutor->fresh());
        $learner = $this->person('Legacy Learner');

        $slot = now('Africa/Johannesburg')->addDays(2)->setTime(10, 0);
        $this->actingAs($learner)->post(route('tutors.sessions.store', $tutor), [
            'subject_id' => $subject->id, 'starts_at' => $slot->format('Y-m-d H:i'), 'duration_minutes' => 60, 'delivery' => 'online',
        ])->assertSessionHasErrors(['subject_id' => "This tutor prices lessons on their offerings. Book one of them from the tutor's page."]);
        $this->assertDatabaseCount('tutor_sessions', 0);
    }

    public function test_operator_words_are_plain_and_chart_ticks_are_integers(): void
    {
        $this->assertSame('Payout batch exported', PlainWords::action('money.payout_batch_exported'));
        $this->assertSame('Money', PlainWords::area('money.payout_batch_exported'));
        $this->assertSame('Tutor profile submitted', PlainWords::action('tutor.profile.submitted'));
        $this->assertSame('Tutor profile', PlainWords::thing('App\\Models\\TutorProfile'));
        $this->assertSame('Thandi', PlainWords::actor(60, 'user:60', [60 => 'Thandi']));
        $this->assertSame('Account 61', PlainWords::actor(61, 'user:61', []));
        $this->assertSame('The system', PlainWords::actor(null, 'system', []));
        $this->assertSame('Amount minor: 5000; Held: yes', PlainWords::details(['amount_minor' => 5000, 'held' => true]));

        $this->assertSame([0, 1, 2], ChartScale::nice(1)['ticks']);
        $this->assertSame([0, 2, 4, 6], ChartScale::nice(4)['ticks']);
        $this->assertSame([0, 10000, 20000, 30000], ChartScale::nice(25500, 100)['ticks']);
        foreach ([0, 1, 2, 3, 7, 13, 99, 1234] as $max) {
            $scale = ChartScale::nice($max);
            $this->assertGreaterThan($max, $scale['top'], "top above {$max}");
            $this->assertSame(array_values(array_unique($scale['ticks'])), $scale['ticks'], 'no repeated tick');
            $this->assertLessThanOrEqual(5, count($scale['ticks']));
        }
    }

    public function test_the_team_page_is_one_line_per_operator_with_roles_behind_a_disclosure(): void
    {
        $admin = $this->admin();
        foreach (range(1, 4) as $i) {
            $u = User::factory()->create(['name' => "Operator {$i}"]);
            app(RoleAdministration::class)->setRoles(null, $u, [OpsRole::Finance->value]);
        }

        $html = $this->actingAs($admin)->get(route('ops.users.team'))->assertOk()
            ->assertSee('Change roles')->assertSee('data-confirm="Remove operator access for Operator 1?', false)
            ->assertSee(OpsRole::Finance->summary())->getContent();
        $this->assertSame(5, substr_count($html, 'class="ops-team__change"'));
    }
}
