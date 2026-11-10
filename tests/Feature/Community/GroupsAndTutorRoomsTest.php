<?php

namespace Tests\Feature\Community;

use App\Domain\Community\Models\GroupMember;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Models\StudySession;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Purchasables;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class GroupsAndTutorRoomsTest extends TestCase
{
    use BuildsCommunity;
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    private function group(User $owner, Room $room, array $overrides = []): int
    {
        return $this->actingAs($owner)->postJson(route('community.api.groups.store', $room), array_merge([
            'name' => 'Paper 1 revision crew',
            'topic' => 'Algebra and equations',
            'exam_focus' => 'November Paper 1',
            'schedule_note' => 'Tuesdays after school',
            'size_cap' => 3,
            'join_rule' => 'open',
        ], $overrides))->assertCreated()->json('group.id');
    }

    private function tutorRoom(User $tutor, array $overrides = []): array
    {
        return $this->actingAs($tutor)->postJson(route('community.api.tutor-rooms.store'), array_merge([
            'curriculum_subject_level_id' => $this->offering()->id,
            'audience' => Room::ADULT,
            'title' => 'Matric maths workshop series',
            'description' => 'Four Saturday workshops on Paper 1.',
            'language' => 'en',
            'join_rule' => 'open',
            'capacity' => 2,
            'price_minor' => 0,
        ], $overrides))->assertCreated()->json('room');
    }

    public function test_study_groups_have_a_size_cap_and_join_rules(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $owner = $this->consentedTeen('Organiser');
        $id = $this->group($owner, $room);

        $this->actingAs($this->consentedTeen('Second'))->postJson(route('community.api.groups.join', $id))->assertOk()->assertJsonPath('status', 'active');
        $this->actingAs($this->consentedTeen('Third'))->postJson(route('community.api.groups.join', $id))->assertOk();
        $this->actingAs($this->consentedTeen('Fourth'))->postJson(route('community.api.groups.join', $id))->assertStatus(409)->assertJsonPath('error', 'group_full');
        $this->assertSame(3, StudyGroup::find($id)->members_count);

        // Approval: the organiser decides. Invite: the code is needed.
        $approval = $this->group($owner, $room, ['name' => 'Approval group', 'join_rule' => 'approval']);
        $asker = $this->consentedTeen('Asker');
        $this->actingAs($asker)->postJson(route('community.api.groups.join', $approval))->assertJsonPath('status', 'pending');
        $member = GroupMember::query()->where('group_id', $approval)->where('user_id', $asker->id)->firstOrFail();
        $this->actingAs($asker)->postJson(route('community.api.groups.members.decide', [$approval, $member]), ['accept' => true])->assertForbidden();
        $this->actingAs($owner)->postJson(route('community.api.groups.members.decide', [$approval, $member]), ['accept' => true])->assertOk()->assertJsonPath('status', 'active');

        $invite = $this->group($owner, $room, ['name' => 'Invite group', 'join_rule' => 'invite']);
        $code = StudyGroup::find($invite)->invite_code;
        $guest = $this->consentedTeen('Guest');
        $this->actingAs($guest)->postJson(route('community.api.groups.join', $invite), ['code' => 'WRONG'])->assertForbidden();
        $this->actingAs($guest)->postJson(route('community.api.groups.join', $invite), ['code' => $code])->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_a_group_in_a_room_for_minors_is_closed_to_unscreened_adults(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $id = $this->group($this->consentedTeen(), $room);

        $this->actingAs($this->adult('Stranger'))->postJson(route('community.api.groups.join', $id))->assertForbidden()->assertJsonPath('error', 'safeguarding_denied');
        $this->actingAs($this->adult('Stranger 2'))->getJson(route('community.api.groups.show', $id))->assertNotFound();
    }

    public function test_sessions_calendar_attendance_revision_list_and_tasks(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $owner = $this->adult('Organiser');
        $mate = $this->adult('Mate');
        $id = $this->group($owner, $room, ['size_cap' => 5]);
        $this->actingAs($mate)->postJson(route('community.api.groups.join', $id))->assertOk();

        $start = now('Africa/Johannesburg')->addDays(2)->setTime(16, 0);
        $session = $this->actingAs($owner)->postJson(route('community.api.groups.sessions.store', $id), [
            'title' => 'Factorising practice', 'starts_at' => $start->format('Y-m-d\TH:i'), 'timezone' => 'Africa/Johannesburg', 'duration_minutes' => 90, 'where_note' => 'School library',
        ])->assertCreated()->json('session');
        $this->assertSame($start->copy()->utc()->toIso8601String(), $session['starts_at']);
        $this->actingAs($mate)->postJson(route('community.api.groups.sessions.store', $id), ['title' => 'Mine', 'starts_at' => $start->format('Y-m-d\TH:i'), 'timezone' => 'Africa/Johannesburg', 'duration_minutes' => 30])->assertForbidden();

        $ics = $this->actingAs($mate)->get($session['calendar_url'])->assertOk();
        $this->assertStringContainsString('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertStringContainsString('DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'), $ics->getContent());
        $this->assertStringContainsString('SUMMARY:Factorising practice', $ics->getContent());

        // Attendance only once the session has started, by the organiser.
        $this->actingAs($owner)->postJson(route('community.api.sessions.attendance', $session['id']), ['attended' => [$mate->id]])->assertStatus(409);
        StudySession::whereKey($session['id'])->update(['starts_at' => now()->subHour()]);
        $this->actingAs($owner)->postJson(route('community.api.sessions.attendance', $session['id']), ['attended' => [$mate->id, 999999]])->assertOk()->assertJsonPath('attended', 1);
        $this->assertSame(1, $this->actingAs($mate)->getJson(route('community.api.indicators'))->json('indicators.sessions_attended'));

        $item = $this->actingAs($mate)->postJson(route('community.api.groups.revision.store', $id), ['title' => 'Difference of squares'])->assertCreated()->json('item.id');
        $this->actingAs($owner)->postJson(route('community.api.revision.tick', $item), ['done' => true])->assertOk();
        $task = $this->actingAs($owner)->postJson(route('community.api.groups.tasks.store', $id), ['title' => 'Do exercise 3.2', 'due_on' => now()->addWeek()->toDateString(), 'assignees' => [$mate->id]])->assertCreated()->json('task.id');
        $this->actingAs($mate)->postJson(route('community.api.tasks.mark', $task), ['done' => true, 'note' => 'Questions 1 to 6'])->assertOk();

        $page = $this->actingAs($owner)->getJson(route('community.api.groups.show', $id))->assertOk();
        $this->assertSame(['Organiser'], array_map(fn ($n) => explode(' ', $n)[0], $page->json('revision.0.done_by')));
        $this->assertSame('Questions 1 to 6', $page->json('tasks.0.assignees.0.note'));

        $resource = $this->libraryResource($owner);
        $this->actingAs($mate)->postJson(route('community.api.groups.resources.store', $id), ['library_resource_id' => $resource->id, 'note' => 'Read before Tuesday'])->assertCreated();
        $outsider = $this->adult('Outsider');
        $this->actingAs($outsider)->postJson(route('community.api.groups.revision.store', $id), ['title' => 'Spam'])->assertForbidden()->assertJsonPath('error', 'not_a_group_member');
    }

    public function test_an_unscreened_tutor_cannot_run_a_room_for_minors_and_only_approved_tutors_run_rooms(): void
    {
        $this->actingAs($this->adult('Not a tutor'))->postJson(route('community.api.tutor-rooms.store'), [
            'curriculum_subject_level_id' => $this->offering()->id, 'audience' => 'adult', 'title' => 'My room here', 'join_rule' => 'open',
        ])->assertForbidden()->assertJsonPath('error', 'not_an_approved_tutor');

        $this->actingAs($this->approvedTutor('Unscreened'))->postJson(route('community.api.tutor-rooms.store'), [
            'curriculum_subject_level_id' => $this->offering()->id, 'audience' => 'under_18', 'title' => 'Grade 10 maths club', 'join_rule' => 'open',
        ])->assertForbidden()->assertJsonPath('reasons.0.code', 'adult_screening_missing');

        $room = $this->tutorRoom($this->approvedTutor('Screened', screened: true), ['audience' => 'under_18']);
        $this->assertSame('under_18', $room['audience']);
    }

    public function test_a_free_tutor_room_respects_capacity_and_members_only_read_it(): void
    {
        $tutor = $this->approvedTutor();
        $room = $this->tutorRoom($tutor);
        $a = $this->adult('First');
        $b = $this->adult('Second');
        $c = $this->adult('Third');

        $this->actingAs($a)->getJson(route('community.api.show', $room['id']))->assertOk()->assertJsonPath('can_read', false);
        $this->actingAs($a)->postJson(route('community.api.posts.store', $room['id']), $this->question())->assertForbidden();

        $this->actingAs($a)->postJson(route('community.api.enrol', $room['id']))->assertOk()->assertJsonPath('status', 'active');
        $this->actingAs($b)->postJson(route('community.api.enrol', $room['id']))->assertOk();
        $this->actingAs($c)->postJson(route('community.api.enrol', $room['id']))->assertStatus(409)->assertJsonPath('error', 'room_full');

        $this->actingAs($a)->getJson(route('community.api.show', $room['id']))->assertOk()->assertJsonPath('membership.status', 'active');
        $this->actingAs($a)->postJson(route('community.api.posts.store', $room['id']), $this->question())->assertCreated();

        // The tutor schedules a workshop; members download it for their calendar.
        $workshop = $this->actingAs($tutor)->postJson(route('community.api.sessions.store', $room['id']), [
            'title' => 'Workshop 1: functions', 'starts_at' => now('Africa/Johannesburg')->addDays(3)->format('Y-m-d\TH:i'), 'timezone' => 'Africa/Johannesburg', 'duration_minutes' => 120,
        ])->assertCreated()->json('session');
        $this->actingAs($a)->get($workshop['calendar_url'])->assertOk();
        $this->actingAs($c)->get($workshop['calendar_url'])->assertNotFound();
    }

    public function test_a_paid_room_is_bought_through_money_and_opens_once_paid(): void
    {
        $this->setUpPaystack();
        $tutor = $this->approvedTutor();
        $room = $this->tutorRoom($tutor, ['price_minor' => 15000, 'capacity' => 10]);
        $learner = $this->adult('Paying Learner');

        $purchasable = app(Purchasables::class)->resolve('room', $room['id']);
        $this->assertSame(15000, $purchasable->purchasePrice($learner)->minor);
        $this->assertSame($tutor->id, $purchasable->sellerId());

        $this->actingAs($learner)->postJson(route('community.api.enrol', $room['id']))->assertStatus(400)->assertJsonPath('error', 'idempotency_key_required');

        $enrol = $this->actingAs($learner)->withHeader('Idempotency-Key', 'room-key-1')->postJson(route('community.api.enrol', $room['id']))
            ->assertStatus(202)->assertJsonPath('status', 'awaiting_payment')->assertJsonPath('checkout.status', 'redirect');
        $this->assertSame(['room', $room['id']], [$enrol->json('checkout.order.lines.0.type'), $enrol->json('checkout.order.lines.0.item_id')]);
        $this->assertSame(15000, Order::query()->latest('id')->first()->total_minor);
        $this->actingAs($learner)->getJson(route('community.api.show', $room['id']))->assertJsonPath('can_read', false);

        // Paystack confirms (verified server side); money settles the order and grants the entitlement.
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$this->paystack->lastReference())->assertRedirect();
        $this->assertSame(Order::PAID, Order::query()->latest('id')->first()->status);

        $this->assertSame(RoomMember::ACTIVE, RoomMember::query()->where('room_id', $room['id'])->where('user_id', $learner->id)->value('status'));
        $this->actingAs($learner)->getJson(route('community.api.show', $room['id']))->assertOk()->assertJsonPath('membership.status', 'active');
        $this->assertSame(1, Room::find($room['id'])->seats_taken);
    }

    public function test_a_minor_needs_a_guardians_approval_before_paying_for_a_room(): void
    {
        $this->setUpPaystack();
        $tutor = $this->approvedTutor('Screened Tutor', screened: true);
        $room = $this->tutorRoom($tutor, ['audience' => 'under_18', 'price_minor' => 5000]);
        $teen = $this->teen('Paying Teen');
        $link = $this->linkGuardian($teen, null, false, 15);

        $this->actingAs($teen)->withHeader('Idempotency-Key', 'teen-room-1')->postJson(route('community.api.enrol', $room['id']))
            ->assertStatus(202)->assertJsonPath('status', 'awaiting_guardian');
        $approval = GuardianApproval::query()->where('learner_id', $teen->id)->where('kind', 'paid_room')->firstOrFail();
        $this->assertSame(0, Order::count());

        app(DatabaseGuardianApprovals::class)->decide($approval, User::find($link->guardian_id), true);

        $this->actingAs($teen)->withHeader('Idempotency-Key', 'teen-room-2')->postJson(route('community.api.enrol', $room['id']))
            ->assertStatus(202)->assertJsonPath('status', 'awaiting_payment');
        $this->assertSame(1, Order::count());
    }
}
