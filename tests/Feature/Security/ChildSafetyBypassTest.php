<?php

namespace Tests\Feature\Security;

use App\Domain\Academic\Models\Country;
use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Contracts\RoomDirectory;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Notifications\GuardianAdded;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Services\Messenger;
use App\Domain\Safety\Support\Participants;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-47 security S-03 to S-07: ways a child's protections could be lifted or walked around.
 */
class ChildSafetyBypassTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;
    use SeedsAcademicStructure;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('safety');
    }

    private function gate(): SafeguardingGate
    {
        return app(SafeguardingGate::class);
    }

    public function test_the_shipped_default_protects_people_of_unknown_age(): void
    {
        $saved = [getenv('SAFETY_UNKNOWN_AGE_POLICY'), $_ENV['SAFETY_UNKNOWN_AGE_POLICY'] ?? null, $_SERVER['SAFETY_UNKNOWN_AGE_POLICY'] ?? null];
        putenv('SAFETY_UNKNOWN_AGE_POLICY');
        unset($_ENV['SAFETY_UNKNOWN_AGE_POLICY'], $_SERVER['SAFETY_UNKNOWN_AGE_POLICY']);

        try {
            $config = require config_path('safety.php');
        } finally {
            putenv('SAFETY_UNKNOWN_AGE_POLICY='.$saved[0]);
            $_ENV['SAFETY_UNKNOWN_AGE_POLICY'] = $saved[1];
            $_SERVER['SAFETY_UNKNOWN_AGE_POLICY'] = $saved[2];
        }

        $this->assertSame('ask', $config['unknown_age_policy']);
    }

    public function test_an_undeclared_learner_is_protected_and_asked_for_a_birth_month(): void
    {
        config(['safety.unknown_age_policy' => 'ask']);
        $silent = $this->makeUser('Grade 2 who skipped onboarding');
        $tutor = $this->adult('Tutor');
        $profile = $this->makeTutor([], null, $tutor);

        $this->assertSame(AgeBand::Child, app(AccountStanding::class)->band($silent));

        $decision = $this->gate()->allows($silent, 'book', $profile);
        $this->assertTrue($decision->denied());
        $this->assertTrue($decision->hasReason('age_unknown'));

        // An unscreened adult cannot open a direct line to them either.
        $this->assertTrue($this->gate()->allows($tutor, 'message', new Participants([$silent->id]), ['context_type' => 'direct'])->denied());

        // An approved tutor or an operator of unknown age is attested as an adult.
        $approved = $this->makeTutor()->user;
        $this->assertSame(AgeBand::Adult, app(AccountStanding::class)->band($approved));
        $this->assertSame(AgeBand::Adult, app(AccountStanding::class)->band($this->operator()));
    }

    public function test_a_learner_cannot_age_themselves_out_of_protection(): void
    {
        $this->seedAcademic();
        $learner = $this->makeUser('Ten year old');
        $payload = fn (int $year) => [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'),
            'academic_level_id' => $this->level('za-caps', 'grade-5')->id,
            'subjects' => [], 'birth_year' => $year, 'birth_month' => 3,
        ];

        $this->actingAs($learner)->put(route('academic.profile.update'), $payload(now()->subYears(10)->year))->assertSessionHasNoErrors();
        $this->assertSame(AgeBand::Child, app(AccountStanding::class)->band($learner));

        $this->actingAs($learner)->put(route('academic.profile.update'), $payload(1990))->assertSessionHasErrors('birth_year');
        $this->assertSame(AgeBand::Child, app(AccountStanding::class)->band($learner->fresh()));

        // Correcting towards a younger band stays open.
        $this->actingAs($learner)->put(route('academic.profile.update'), $payload(now()->subYears(8)->year))->assertSessionHasNoErrors();
    }

    public function test_a_parent_of_one_child_is_not_cleared_for_other_peoples_children(): void
    {
        $mine = $this->child('Mine');
        $other = $this->child('Other');
        $parent = $this->adult('Parent of Mine');
        $this->linkGuardian($mine, $parent);
        $this->linkGuardian($other);

        $room = ['context_type' => 'room'];
        $this->assertTrue($this->gate()->allows($parent, 'message', new Participants([$mine->id]), $room)->allowed);
        $this->assertTrue($this->gate()->allows($parent, 'message', new Participants([$mine->id, $other->id]), $room)->hasReason('tutor_screening_missing'));
    }

    public function test_a_guardian_added_to_a_room_thread_cannot_write_to_other_children_unscreened(): void
    {
        $a = $this->child('Ayanda');
        $b = $this->child('Busi');
        $guardianOfA = $this->adult('Guardian A');
        $this->linkGuardian($a, $guardianOfA);
        $this->linkGuardian($b);
        $room = $this->bindRoom([$a->id, $b->id], [AgeBand::Child]);

        $message = app(Messenger::class)->start($a, Conversation::ROOM, $room, [$b->id], 'Hello Busi');
        $conversation = $message->conversation;
        $this->assertNotNull($conversation->participantFor($guardianOfA), 'The guardian oversees their child\'s thread');

        $this->expectException(SafeguardingDenied::class);
        app(Messenger::class)->send($conversation, $guardianOfA, 'Hi Busi, I am Ayanda\'s dad');
    }

    public function test_a_room_thread_closes_for_someone_who_left_the_room(): void
    {
        $a = $this->child('Ayanda');
        $b = $this->child('Busi');
        $this->linkGuardian($a);
        $this->linkGuardian($b);
        $room = $this->bindRoom([$a->id, $b->id], [AgeBand::Child]);

        $conversation = app(Messenger::class)->start($a, Conversation::ROOM, $room, [$b->id], 'Hello')->conversation;

        $this->bindRoom([$b->id], [AgeBand::Child]); // Ayanda was removed from the room

        $decision = $this->gate()->allows($a, 'message', $conversation->fresh());
        $this->assertTrue($decision->denied());
    }

    public function test_a_tutor_cannot_message_a_minor_about_a_booking_a_guardian_has_not_approved(): void
    {
        $child = $this->child('Lerato');
        $this->linkGuardian($child);
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);
        $profile = $this->makeTutor([], null, $tutor);
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($profile, $child, $this->makeSubject('Maths'), ['status' => SessionStatus::AwaitingGuardian->value]));

        $this->actingAs($tutor)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hi, call me on WhatsApp',
        ])->assertForbidden();

        TutorSession::withoutEvents(fn () => $session->forceFill(['status' => SessionStatus::Confirmed->value])->save());
        $this->actingAs($tutor)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'See you on Tuesday',
        ])->assertCreated();

        TutorSession::withoutEvents(fn () => $session->forceFill(['status' => SessionStatus::Cancelled->value])->save());
        $conversation = Conversation::query()->firstOrFail();
        $this->assertTrue($this->gate()->allows($tutor, 'message', $conversation)->denied());
    }

    public function test_a_second_guardian_cannot_move_a_confirmed_birth_date_and_the_first_is_told(): void
    {
        Notification::fake();
        $child = $this->child('Lerato');
        $mother = $this->adult('Mother');
        $this->linkGuardian($child, $mother);
        Notification::fake();

        $sock = $this->adult('Caregiver');
        $guardians = app(Guardians::class);
        $result = $guardians->invite($child, $sock->email, 'caregiver');
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        try {
            $guardians->accept($result['invitation'], $query['token'], $sock, CarbonImmutable::now()->subYears(17));
            $this->fail('A different birth date was accepted');
        } catch (ValidationException) {
        }

        $this->assertSame(AgeBand::Child, app(AccountStanding::class)->band($child));

        $guardians->accept($result['invitation']->fresh(), $query['token'], $sock, CarbonImmutable::now()->subYears(9)->subMonths(2));
        Notification::assertSentTo($mother, GuardianAdded::class);
    }

    /** @param list<int> $members @param list<AgeBand> $bands */
    private function bindRoom(array $members, array $bands): int
    {
        $space = new class($members, $bands) implements AgeGatedSpace
        {
            public function __construct(private array $members, private array $bands) {}

            public function safetyAgeBands(): array
            {
                return $this->bands;
            }

            public function safetyIsPaid(): bool
            {
                return false;
            }

            public function safetyMemberIds(): array
            {
                return $this->members;
            }
        };

        $this->app->instance(RoomDirectory::class, new class($space) implements RoomDirectory
        {
            public function __construct(private AgeGatedSpace $space) {}

            public function find(int $roomId): ?AgeGatedSpace
            {
                return $roomId === 77 ? $this->space : null;
            }
        });

        // Messenger and the gate resolve the directory when built: rebuild them.
        $this->app->forgetInstance(Messenger::class);

        return 77;
    }
}
