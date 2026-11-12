<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

class ProfileVerificationTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function jpeg(int $w = 1600, int $h = 1200): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 30, 90, 160));
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();
        // Append a trailer after the JPEG end marker, as a smuggled payload would be.
        $bytes .= 'GPS:-26.2041,28.0473';

        return UploadedFile::fake()->createWithContent('me.jpg', $bytes);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('degree.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function submittedQualification(TutorProfile $tutor, string $kind = 'degree'): int
    {
        $id = $this->actingAs($tutor->user)->postJson('/api/tutor/me/qualifications', [
            'kind' => $kind, 'title' => 'BSc Mathematics', 'institution' => 'University of the Witwatersrand', 'year_awarded' => 2019,
        ])->assertCreated()->assertJsonPath('data.status', 'unverified')->json('data.id');

        $this->actingAs($tutor->user)->post("/api/tutor/me/qualifications/{$id}/evidence", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($tutor->user)->postJson("/api/tutor/me/qualifications/{$id}/submit")->assertJsonPath('data.status', 'submitted');

        return $id;
    }

    public function test_the_tutor_updates_profile_v2_and_rates_stay_in_step(): void
    {
        $tutor = $this->makeTutor(['hourly_rate' => 350]);
        $this->assertSame(35000, $tutor->fresh()->hourly_rate_minor);

        $this->actingAs($tutor->user)->patchJson('/api/tutor/me/profile', [
            'headline' => 'Maths and Physical Sciences, Grades 10 to 12',
            'introduction' => 'I taught for six years.',
            'teaching_approach' => 'Past papers first, then the gaps.',
            'years_experience' => 6,
            'teaches_in_person' => true,
            'in_person_area' => 'Soweto',
            'hourly_rate_minor' => 42050,
            'public_handle' => 'thandi-maths',
        ])->assertOk()->assertJsonPath('data.introduction', 'I taught for six years.')
            ->assertJsonPath('data.formats.in_person_area', 'Soweto');

        $this->assertSame('420.50', $tutor->fresh()->hourly_rate);
        $this->actingAs($tutor->user)->patchJson('/api/tutor/me/profile', ['teaches_online' => false, 'teaches_in_person' => false])
            ->assertStatus(422);
    }

    public function test_verification_fields_cannot_be_set_by_the_tutor(): void
    {
        $tutor = $this->makeTutor();
        $this->actingAs($tutor->user)->patchJson('/api/tutor/me/profile', ['verification_level' => 'professional_educator', 'headline' => 'x'])->assertOk();

        $this->assertNull($tutor->fresh()->verification_level);
    }

    public function test_the_photo_is_re_encoded_resized_and_private(): void
    {
        $tutor = $this->makeTutor();
        $this->actingAs($tutor->user)->post('/api/tutor/me/profile/photo', ['photo' => $this->jpeg()], ['Accept' => 'application/json'])->assertCreated();

        $path = $tutor->fresh()->photo_path;
        $this->assertStringStartsWith('marketplace/photos/', $path);
        $stored = Storage::disk('local')->get($path);
        [$w, $h] = getimagesizefromstring($stored);
        $this->assertSame(800, max($w, $h));
        $this->assertStringNotContainsString('GPS:', $stored);

        $this->actingAs($this->makeUser())->get("/tutors/{$tutor->id}/photo")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $tutor->update(['status' => 'suspended']);
        $this->actingAs($this->makeUser())->get("/tutors/{$tutor->id}/photo")->assertNotFound();
    }

    public function test_a_file_that_is_not_an_image_is_refused_as_a_photo(): void
    {
        $tutor = $this->makeTutor();
        $fake = UploadedFile::fake()->createWithContent('me.jpg', '<?php echo "hi";');

        $this->actingAs($tutor->user)->post('/api/tutor/me/profile/photo', ['photo' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->assertNull($tutor->fresh()->photo_path);
    }

    public function test_a_qualification_needs_evidence_before_review(): void
    {
        $tutor = $this->makeTutor();
        $id = $this->actingAs($tutor->user)->postJson('/api/tutor/me/qualifications', ['kind' => 'degree', 'title' => 'BEd'])->json('data.id');

        $this->actingAs($tutor->user)->postJson("/api/tutor/me/qualifications/{$id}/submit")->assertStatus(422)->assertJsonPath('error', 'evidence_required');
        $this->actingAs($tutor->user)->post("/api/tutor/me/qualifications/{$id}/evidence", ['file' => UploadedFile::fake()->createWithContent('x.pdf', 'MZ not a pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_an_operator_verifies_evidence_then_assigns_a_supported_level_with_an_audit_trail(): void
    {
        $tutor = $this->makeTutor();
        $operator = $this->makeOperator();
        $id = $this->submittedQualification($tutor);

        // No level without verified supporting evidence.
        $this->actingAs($operator)->postJson("/api/tutor/operator/tutors/{$tutor->id}/verification-level", ['level' => 'verified_qualified_tutor', 'reason' => 'Looks good'])
            ->assertStatus(422)->assertJsonPath('error', 'evidence_missing');

        $this->actingAs($operator)->getJson('/api/tutor/operator/qualifications')->assertOk()->assertJsonPath('data.0.id', $id);
        $evidence = TutorQualification::find($id)->evidence()->first();
        $this->actingAs($operator)->get("/api/tutor/operator/evidence/{$evidence->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($operator)->postJson("/api/tutor/operator/qualifications/{$id}/verify", ['note' => 'Certificate matches the Wits register.'])
            ->assertOk()->assertJsonPath('data.status', 'verified');

        // A degree does not support "professional educator".
        $this->actingAs($operator)->postJson("/api/tutor/operator/tutors/{$tutor->id}/verification-level", ['level' => 'professional_educator', 'reason' => 'x x x'])
            ->assertStatus(422);
        $this->actingAs($operator)->postJson("/api/tutor/operator/tutors/{$tutor->id}/verification-level", ['level' => 'verified_qualified_tutor', 'reason' => 'Degree verified.'])
            ->assertOk()->assertJsonPath('data.label', 'Verified qualified tutor');

        $history = $this->actingAs($operator)->getJson("/api/tutor/operator/tutors/{$tutor->id}/verification-history")->json('data');
        $this->assertSame(['qualification', 'level'], array_column($history, 'subject'));
        $this->assertSame([$evidence->id], $history[0]['evidence_ids']);

        $this->expectException(LogicException::class);
        VerificationDecision::first()->delete();
    }

    public function test_a_verified_record_is_locked_and_revoking_it_removes_the_level(): void
    {
        $tutor = $this->makeTutor();
        $operator = $this->makeOperator();
        $id = $this->submittedQualification($tutor, 'student_enrolment');
        $this->actingAs($operator)->postJson("/api/tutor/operator/qualifications/{$id}/verify", ['note' => 'Enrolment letter checked.']);
        $this->actingAs($operator)->postJson("/api/tutor/operator/tutors/{$tutor->id}/verification-level", ['level' => 'peer_student_tutor', 'reason' => 'Current student.']);

        $this->actingAs($tutor->user)->patchJson("/api/tutor/me/qualifications/{$id}", ['title' => 'PhD'])->assertStatus(409)->assertJsonPath('error', 'locked');

        $this->actingAs($operator)->postJson("/api/tutor/operator/qualifications/{$id}/revoke", ['note' => 'Enrolment ended.'])->assertOk();
        $this->assertNull($tutor->fresh()->verification_level);
    }

    public function test_operators_cannot_review_themselves_and_learners_cannot_review_at_all(): void
    {
        $operatorTutor = $this->makeTutor([], null, $this->makeOperator());
        $id = $this->submittedQualification($operatorTutor);

        $this->actingAs($operatorTutor->user)->postJson("/api/tutor/operator/qualifications/{$id}/verify", ['note' => 'Mine, trust me.'])
            ->assertForbidden()->assertJsonPath('error', 'conflict_of_interest');
        $this->actingAs($this->makeUser())->postJson("/api/tutor/operator/qualifications/{$id}/verify", ['note' => 'Hello there'])->assertForbidden();
    }

    public function test_evidence_is_readable_only_by_its_owner_and_operators(): void
    {
        $tutor = $this->makeTutor();
        $id = $this->submittedQualification($tutor);
        $evidence = TutorQualification::find($id)->evidence()->first();

        $this->actingAs($tutor->user)->get("/api/tutor/me/evidence/{$evidence->id}")->assertOk();
        $other = $this->makeTutor([], null, $this->makeUser('Other tutor'));
        $this->actingAs($other->user)->get("/api/tutor/me/evidence/{$evidence->id}")->assertNotFound();
        $this->actingAs($other->user)->get("/api/tutor/operator/evidence/{$evidence->id}")->assertForbidden();
    }

    public function test_public_labels_never_claim_more_than_was_verified(): void
    {
        $tutor = $this->makeTutor(['qualifications' => 'BSc, PGCE, 10 years at a top school']);
        $this->submittedQualification($tutor);
        $rejected = TutorQualification::create(['tutor_profile_id' => $tutor->id, 'kind' => 'teaching_registration', 'title' => 'SACE registration']);
        $rejected->forceFill(['status' => 'rejected'])->save();

        $store = $this->actingAs($this->makeUser())->getJson("/api/tutor/tutors/{$tutor->id}")->assertOk()->json('data');

        $this->assertSame('Not yet verified', $store['verification']['label']);
        $this->assertSame(['Self-declared, evidence under review'], array_column($store['qualifications'], 'status_label'));
        $this->assertSame('Self-declared, not verified', $store['self_declared_qualifications']['label']);
        $this->assertArrayNotHasKey('evidence', $store['qualifications'][0]);

        foreach (['qualified', 'professional', 'teacher', 'educator'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, VerificationLevel::PeerStudentTutor->label());
        }
        $this->assertStringContainsString('not a teaching qualification', VerificationLevel::PeerStudentTutor->description());
    }

    public function test_minor_unit_companions_follow_the_decimal_columns(): void
    {
        $tutor = $this->makeTutor(['hourly_rate' => '199.99']);
        $session = $this->makeSession($tutor, $this->makeUser(), $tutor->subjects()->first(), ['rate' => '199.99']);

        $this->assertSame(19999, $tutor->fresh()->hourly_rate_minor);
        $this->assertSame(19999, $session->fresh()->rate_minor);
    }
}
