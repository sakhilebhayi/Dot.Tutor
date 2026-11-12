<?php

namespace Tests\Feature\Classroom;

use App\Domain\Classroom\Models\AttendanceSpan;
use App\Domain\Classroom\Models\ClassroomMessage;
use App\Domain\Classroom\Models\ClassroomSignal;
use App\Domain\Classroom\Services\AfterLesson;
use App\Domain\Classroom\Services\ClassroomService;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Domain\Safety\Services\Guardians;
use App\Models\LessonResource;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Signalling storage and purge, attendance from real joins, chat, whiteboard, shared notes and files. */
class ClassroomRoomTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: TutorSession, 3: int, 4: int} */
    private function twoInRoom(): array
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner Sipho');
        $session = $this->lesson($profile, $learner);
        $t = $this->joinAs($tutor, $session, 'tt')->assertOk()->json('attendee_id');
        $l = $this->joinAs($learner, $session, 'll')->assertOk()->json('attendee_id');

        return [$tutor, $learner, $session, $t, $l];
    }

    private function signal($user, $session, string $client, ?int $to, string $type = 'offer', string $payload = '{"d":"sdp"}')
    {
        return $this->actingAs($user)->withHeader('X-Client-Id', $client)->postJson(route('classroom.api.signal', $session), ['to' => $to, 'type' => $type, 'payload' => $payload]);
    }

    public function test_signals_reach_only_the_person_they_are_for_and_are_purged(): void
    {
        config(['classroom.signal_ttl_seconds' => 30]);
        [$tutor, $learner, $session, $t, $l] = $this->twoInRoom();

        $this->signal($tutor, $session, 'tt', $l)->assertCreated();
        $this->signal($tutor, $session, 'tt', 999999)->assertStatus(409)->assertJsonPath('error', 'not_in_room');
        $this->signal($tutor, $session, 'tt', $l, 'evil')->assertStatus(422);

        $mine = $this->pollAs($tutor, $session, 'tt')->assertOk();
        $this->assertSame([], $mine->json('signals'), 'the sender does not get their own signal back');

        $theirs = $this->pollAs($learner, $session, 'll')->assertOk();
        $this->assertSame('offer', $theirs->json('signals.0.type'));
        $this->assertSame($t, $theirs->json('signals.0.from'));
        $last = $theirs->json('last_id');
        $this->assertSame([], $this->pollAs($learner, $session, 'll', ['since' => $last])->json('signals'), 'since skips what was read');

        $this->assertSame(1, ClassroomSignal::query()->count());
        $this->travel(config('classroom.signal_ttl_seconds') + 5)->seconds();
        $this->pollAs($learner, $session, 'll')->assertOk();
        $this->assertSame(0, ClassroomSignal::query()->count(), 'expired signals are deleted on the next poll');

        $this->signal($tutor, $session, 'tt', $l)->assertCreated();
        $this->travel(config('classroom.signal_ttl_seconds') + 5)->seconds();
        $this->artisan('classroom:sweep')->assertSuccessful();
        $this->assertSame(0, ClassroomSignal::query()->count(), 'the sweep deletes them too');
    }

    public function test_ending_the_lesson_deletes_every_signal(): void
    {
        [$tutor, , $session, , $l] = $this->twoInRoom();
        $this->signal($tutor, $session, 'tt', $l)->assertCreated();

        $this->actingAs($tutor)->postJson(route('classroom.api.end', $session))->assertOk();
        $this->assertSame(0, ClassroomSignal::query()->count());
    }

    public function test_a_second_window_takes_over_and_the_first_is_told(): void
    {
        [, $learner, $session] = $this->twoInRoom();

        $this->joinAs($learner, $session, 'second')->assertOk();
        $this->pollAs($learner, $session, 'll')->assertStatus(409)->assertJsonPath('error', 'displaced');
        $this->pollAs($learner, $session, 'second')->assertOk();
    }

    public function test_attendance_counts_real_presence_across_rejoins_and_lost_connections(): void
    {
        $this->freezeTime();
        [$tutor, $learner, $session] = $this->twoInRoom();

        // Browsers poll every few seconds; minute steps are enough here (a person is dropped after 90 s of silence).
        $minutes = function (int $n, bool $learnerToo, string $client = 'll') use ($tutor, $learner, $session) {
            foreach (range(1, $n) as $i) {
                $this->travel(1)->minutes();
                if ($learnerToo) {
                    $this->pollAs($learner, $session, $client)->assertOk();
                }
                $this->pollAs($tutor, $session, 'tt')->assertOk();
            }
        };

        // The learner stays 20 minutes, then leaves properly.
        $minutes(20, true);
        $this->actingAs($learner)->withHeader('X-Client-Id', 'll')->postJson(route('classroom.api.leave', $session))->assertOk();

        // Comes back two minutes later for 5 minutes, then the browser dies (no leave); the tutor keeps polling.
        $minutes(2, false);
        $this->joinAs($learner, $session, 'l2')->assertOk();
        $minutes(5, true, 'l2');
        $minutes(2, false);

        $spans = AttendanceSpan::query()->where('user_id', $learner->id)->orderBy('id')->get();
        $this->assertCount(2, $spans);
        $this->assertSame('left', $spans[0]->left_reason);
        $this->assertSame('lost', $spans[1]->left_reason);
        $this->assertSame(20 * 60, $spans[0]->seconds());
        $this->assertSame(5 * 60, $spans[1]->seconds(), 'a lost browser counts to its last sign of life, not to when it was noticed');

        $classroom = app(ClassroomService::class)->for($session);
        $rows = collect(app(AfterLesson::class)->attendance($classroom))->keyBy('user_id');
        $this->assertSame(25, $rows[$learner->id]['minutes']);
        $this->assertSame(2, $rows[$learner->id]['joins']);
        $this->assertSame(29, $rows[$tutor->id]['minutes']);
    }

    public function test_chat_hides_contact_details_when_a_minor_is_in_the_lesson_and_messages_can_be_reported(): void
    {
        [$tutor, $profile] = $this->tutor();
        $teen = $this->teen('Ayanda');
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($teen, $guardian);
        $session = $this->lesson($profile, $teen);
        $this->joinAs($tutor, $session, 'tt')->assertOk();
        $this->joinAs($teen, $session, 'll')->assertOk();

        $sent = $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->postJson(route('classroom.api.chat', $session), ['body' => 'Call me on 082 555 1234 after class'])->assertCreated();
        $this->assertStringContainsString('[phone hidden]', $sent->json('message.body'));
        $this->assertTrue($sent->json('message.redacted'));
        $stored = ClassroomMessage::query()->sole();
        $this->assertSame('Call me on 082 555 1234 after class', $stored->original_body);
        $this->assertArrayNotHasKey('original_body', $stored->toArray());

        $chat = $this->pollAs($teen, $session, 'll')->json('chat');
        $this->assertCount(1, $chat);
        $this->assertStringNotContainsString('082', $chat[0]['body']);

        $this->actingAs($teen)->postJson(route('classroom.api.report', [$session, $stored->id]), ['category' => 'inappropriate_contact_minor', 'details' => 'Asked me to call'])->assertCreated();
        $report = Report::query()->sole();
        $this->assertSame($stored->id, (int) $report->reportable_id);
        $this->actingAs($this->adult('Outsider'))->postJson(route('classroom.api.report', [$session, $stored->id]), ['category' => 'spam'])->assertForbidden();
    }

    public function test_chat_between_adults_is_not_redacted(): void
    {
        [$tutor, , $session] = $this->twoInRoom();
        $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->postJson(route('classroom.api.chat', $session), ['body' => 'My office line is 021 555 1234'])
            ->assertCreated()->assertJsonPath('message.body', 'My office line is 021 555 1234');
    }

    public function test_whiteboard_ops_are_validated_and_replayed_in_order(): void
    {
        [$tutor, $learner, $session] = $this->twoInRoom();
        $post = fn ($user, $client, $body) => $this->actingAs($user)->withHeader('X-Client-Id', $client)->postJson(route('classroom.api.board', $session), $body);

        $post($tutor, 'tt', ['page' => 1, 'kind' => 'pen', 'data' => ['points' => [[0.1, 0.1], [0.5, 2]], 'colour' => 'red; background:url(x)', 'width' => 99]])->assertCreated();
        $post($learner, 'll', ['page' => 1, 'kind' => 'text', 'data' => ['at' => [0.2, 0.3], 'text' => 'x = 4']])->assertCreated();
        $post($tutor, 'tt', ['page' => 2, 'kind' => 'pen', 'data' => ['points' => [[0, 0]]]])->assertStatus(422)->assertJsonPath('error', 'bad_page');
        $post($tutor, 'tt', ['page' => 1, 'kind' => 'script', 'data' => []])->assertStatus(422);
        $post($tutor, 'tt', ['page' => 1, 'kind' => 'pen', 'data' => ['points' => [['a', 'b']]]])->assertStatus(422);

        $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->postJson(route('classroom.api.board.page', $session))->assertCreated()->assertJsonPath('pages', 2);

        $ops = $this->pollAs($learner, $session, 'll')->json('board');
        $this->assertCount(2, $ops);
        $this->assertSame(['points' => [[0.1, 0.1], [0.5, 1]], 'width' => 40, 'colour' => 'ink'], $ops[0]['data'], 'clamped to the page, the width cap and a named colour');
        $this->assertSame('x = 4', $ops[1]['data']['text']);
        $this->assertSame([], $this->pollAs($learner, $session, 'll', ['board_since' => $ops[1]['id']])->json('board'));
    }

    public function test_shared_notes_refuse_a_stale_save_instead_of_overwriting(): void
    {
        [$tutor, $learner, $session] = $this->twoInRoom();
        $save = fn ($user, $client, $body, $version) => $this->actingAs($user)->withHeader('X-Client-Id', $client)->postJson(route('classroom.api.notes', $session), ['body' => $body, 'version' => $version]);

        $save($tutor, 'tt', 'Factorise: x^2 - 9', 0)->assertOk()->assertJsonPath('version', 1);
        $save($learner, 'll', 'My answer', 0)->assertStatus(409)->assertJsonPath('error', 'notes_conflict')->assertJsonPath('body', 'Factorise: x^2 - 9');
        $save($learner, 'll', "Factorise: x^2 - 9\n(x-3)(x+3)", 1)->assertOk()->assertJsonPath('version', 2);

        $this->assertSame("Factorise: x^2 - 9\n(x-3)(x+3)", $this->pollAs($tutor, $session, 'tt', ['notes_version' => 1])->json('notes.body'));
        $this->assertNull($this->pollAs($tutor, $session, 'tt', ['notes_version' => 2])->json('notes'));
    }

    public function test_files_shared_in_the_room_become_lesson_resources_for_participants_only(): void
    {
        Storage::fake('lesson-resources');
        [$tutor, $learner, $session] = $this->twoInRoom();

        $pdf = UploadedFile::fake()->createWithContent('worksheet.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->post(route('classroom.api.upload', $session), ['file' => $pdf, 'title' => 'Worksheet 3'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('title', 'Worksheet 3');
        $exe = UploadedFile::fake()->createWithContent('game.pdf', "MZ\x90\x00\x03binary");
        $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->post(route('classroom.api.upload', $session), ['file' => $exe], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'file_type');

        $resource = LessonResource::query()->sole();
        $this->assertSame($session->id, $resource->session_id);
        $files = $this->pollAs($learner, $session, 'll')->json('files');
        $this->assertSame('Worksheet 3', $files[0]['title']);

        $this->actingAs($learner)->get(route('classroom.files.show', [$session, $resource]))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->adult('Outsider'))->get(route('classroom.files.show', [$session, $resource]))->assertForbidden();
    }

    public function test_the_tutors_mute_command_reaches_only_the_target(): void
    {
        [$tutor, $learner, $session, , $l] = $this->twoInRoom();
        $this->actingAs($tutor)->withHeader('X-Client-Id', 'tt')->postJson(route('classroom.api.tutor', $session), ['action' => 'mute', 'attendee_id' => $l])->assertOk();

        $this->assertSame('command', $this->pollAs($learner, $session, 'll')->json('signals.0.type'));
        $this->assertSame([], $this->pollAs($tutor, $session, 'tt')->json('signals'));
    }

    public function test_a_guardian_withdrawing_consent_takes_the_minor_out_on_the_next_check(): void
    {
        [$tutor, $profile] = $this->tutor();
        $child = $this->child('Lerato');
        $guardian = $this->adult('Mother');
        $link = $this->linkGuardian($child, $guardian);
        $session = $this->lesson($profile, $child);
        $approvals = app(DatabaseGuardianApprovals::class);
        $approvals->decide($approvals->request($child, 'live_lesson', $session), $guardian, true);
        $this->joinAs($child, $session, 'cc')->assertOk();

        app(Guardians::class)->revoke($link, $guardian, 'Not today');
        $this->travel(31)->seconds(); // the admission is re-checked at most every 30 seconds

        $this->pollAs($child, $session, 'cc')->assertForbidden()->assertJsonPath('error', 'guardian_consent_required');
        $this->assertNotNull(AttendanceSpan::query()->where('user_id', $child->id)->value('left_at'));
    }
}
