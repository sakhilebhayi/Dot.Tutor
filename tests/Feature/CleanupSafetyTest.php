<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteUser;
use App\Models\LessonResource;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class CleanupSafetyTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function sessionWithFiles(int $count = 2): array
    {
        Storage::fake('lesson-resources');
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser('Student');
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed']);
        $resources = [];
        for ($i = 1; $i <= $count; $i++) {
            $path = "worksheet-{$i}.pdf";
            Storage::disk('lesson-resources')->put($path, 'bytes');
            $resources[] = LessonResource::create(['session_id' => $session->id, 'uploader_id' => $student->id, 'title' => "W{$i}", 'file_path' => $path, 'file_type' => 'pdf']);
        }

        return [$session, $resources, $profile, $student, $subject];
    }

    public function test_deleting_a_resource_removes_its_file(): void
    {
        [, $resources] = $this->sessionWithFiles(1);
        Storage::disk('lesson-resources')->assertExists('worksheet-1.pdf');

        $resources[0]->delete();

        Storage::disk('lesson-resources')->assertMissing('worksheet-1.pdf');
    }

    public function test_deleting_a_session_removes_all_its_files(): void
    {
        [$session] = $this->sessionWithFiles(2);

        $session->delete();

        Storage::disk('lesson-resources')->assertMissing('worksheet-1.pdf');
        Storage::disk('lesson-resources')->assertMissing('worksheet-2.pdf');
        $this->assertSame(0, LessonResource::count());
    }

    public function test_deleting_a_session_leaves_other_sessions_files_alone(): void
    {
        [$session, , $profile, $student, $subject] = $this->sessionWithFiles(1);
        $other = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(5)]);
        Storage::disk('lesson-resources')->put('keep.pdf', 'x');
        LessonResource::create(['session_id' => $other->id, 'uploader_id' => $student->id, 'title' => 'Keep', 'file_path' => 'keep.pdf']);

        $session->delete();

        Storage::disk('lesson-resources')->assertExists('keep.pdf');
    }

    public function test_deleting_a_session_refreshes_the_tutors_rating(): void
    {
        [$session, , $profile, $student] = $this->sessionWithFiles(0);
        $session->update(['status' => 'completed']);
        SessionRating::create(['session_id' => $session->id, 'rated_by' => $student->id, 'rating' => 5]);
        $profile->refreshRating();
        $this->assertEquals(5, $profile->fresh()->rating);

        $session->delete();

        $this->assertEquals(0, $profile->fresh()->rating);
    }

    public function test_the_resource_destroy_route_still_removes_row_and_file(): void
    {
        [$session, $resources, , $student] = $this->sessionWithFiles(1);

        $this->actingAs($student)->delete(route('resources.destroy', $resources[0]))->assertRedirect(route('sessions.show', $session));

        Storage::disk('lesson-resources')->assertMissing('worksheet-1.pdf');
        $this->assertSame(0, LessonResource::count());
    }

    public function test_deleting_a_student_account_keeps_the_tutors_history(): void
    {
        [$session, $resources, $profile, $student] = $this->sessionWithFiles(1);
        $session->update(['status' => 'completed']);
        SessionRating::create(['session_id' => $session->id, 'rated_by' => $student->id, 'rating' => 4]);
        $profile->refreshRating();

        app(DeleteUser::class)->delete($student);

        $this->assertNull(User::find($student->id));
        $kept = $session->fresh();
        $this->assertNotNull($kept, 'the tutor lost a session when a student deleted their account');
        $this->assertNull($kept->student_id);
        $this->assertSame('completed', $kept->status);
        $this->assertSame(1, SessionRating::count());
        $this->assertNull(SessionRating::firstOrFail()->rated_by);
        $this->assertSame(1, LessonResource::count());
        $this->assertNull($resources[0]->fresh()->uploader_id);
        Storage::disk('lesson-resources')->assertExists('worksheet-1.pdf');
        $this->assertEquals(4, $profile->fresh()->rating);

        // The tutor can still open the session; the missing student is shown honestly.
        $this->actingAs($profile->user)->get(route('sessions.show', $session))->assertOk()->assertSee('Deleted account');
    }

    public function test_deleting_a_student_cancels_their_open_bookings_so_tutor_time_is_freed(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        $pending = $this->makeSession($profile, $student, $subject, ['starts_at' => now()->addDays(2)]);
        $confirmed = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(3)]);
        $past = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(3)]);

        app(DeleteUser::class)->delete($student);

        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('cancelled', $confirmed->fresh()->status);
        $this->assertSame('completed', $past->fresh()->status);
    }

    public function test_deleting_a_tutor_account_keeps_every_students_history_and_takes_the_tutor_off_the_market(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser('Student');
        $done = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(3)]);
        $upcoming = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(3)]);
        SessionRating::create(['session_id' => $done->id, 'rated_by' => $student->id, 'rating' => 5]);

        app(DeleteUser::class)->delete($profile->user);

        $this->assertNotNull($done->fresh());
        $this->assertNotNull(TutorProfile::find($profile->id), 'the profile row carries the students history and must survive');
        $this->assertNull($profile->fresh()->user_id);
        $this->assertSame('suspended', $profile->fresh()->status);
        $this->assertSame('cancelled', $upcoming->fresh()->status);
        $this->assertSame(1, SessionRating::count());

        $this->actingAs($student)->get('/tutors')->assertOk()->assertDontSee('Approved Tutor');
        $this->actingAs($student)->get("/tutors/{$profile->id}")->assertNotFound();
        $this->actingAs($student)->get(route('sessions.show', $done))->assertOk()->assertSee('Deleted account');
    }

    public function test_the_database_itself_refuses_to_cascade_a_tutor_profile_or_subject_away_from_history(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $this->makeSession($profile, $this->makeUser(), $subject);

        $this->expectException(QueryException::class);
        $profile->delete();
    }

    public function test_tutor_sessions_has_the_hot_path_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('tutor_sessions'))->map(fn ($i) => $i['columns'])->all();

        $this->assertContains(['tutor_profile_id', 'starts_at'], $indexes);
        $this->assertContains(['student_id', 'status'], $indexes);
        $this->assertContains(['status'], $indexes);
    }

    public function test_uploads_are_throttled_per_user(): void
    {
        Storage::fake('lesson-resources');
        [$session, , , $student] = $this->sessionWithFiles(0);
        RateLimiter::clear('uploads');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($student)->post(route('resources.store', $session), [
                'title' => "Doc {$i}",
                'file' => UploadedFile::fake()->create("d{$i}.pdf", 10, 'application/pdf'),
            ])->assertStatus(302);
        }

        $this->actingAs($student)->post(route('resources.store', $session), [
            'title' => 'One too many',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(429);
        $this->assertSame(10, LessonResource::count());
    }
}
