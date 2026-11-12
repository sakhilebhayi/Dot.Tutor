<?php

namespace Tests\Feature\Perf;

use App\Domain\Community\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Community\BuildsCommunity;
use Tests\TestCase;

/** The room activity poll (every 30 s per open room tab): an unchanged room is a cheap 304 (R-47 D-1608). */
class RoomPollCostTest extends TestCase
{
    use BuildsCommunity;
    use MeasuresRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    public function test_an_unchanged_room_answers_304_without_counting_questions_or_writing(): void
    {
        $this->seedAcademic();
        $room = $this->subjectRoom(Room::ADULT);
        $adult = $this->adult('Room Reader');
        $session = $this->signedInSession($adult);
        $first = $this->asBrowser($session)->getJson(route('rooms.activity', $room))->assertOk();
        $etag = (string) $first->headers->get('ETag');

        [$response, $statements, $writes] = $this->measure(fn () => $this->asBrowser($session, ['If-None-Match' => $etag])->getJson(route('rooms.activity', $room)));

        $response->assertStatus(304);
        $this->assertLessThanOrEqual(14, $statements, 'statements for an unchanged room poll');
        $this->assertSame(0, $writes, 'an unchanged room poll writes nothing');
        $this->assertStringNotContainsString('sum(case', implode("\n", $this->statements), 'question counts are not computed for a 304');
    }
}
