<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceLibraryTest extends TestCase
{
    use LearningFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('learning');
    }

    /** @param  array<string, mixed>  $overrides */
    private function upload(array $overrides = [], ?UploadedFile $file = null)
    {
        return $this->post('/api/tutor/learning/library', array_merge([
            'title' => 'Grade 12 Calculus summary',
            'description' => 'Derivatives from first principles, rules and applications.',
            'type' => 'summary',
            'country_code' => 'za',
            'subject_id' => $this->subject()->id,
            'topic' => 'Differential calculus',
            'language' => 'en',
            'difficulty' => 'standard',
            'ownership' => 'own_work',
            'licence' => 'cc_by',
            'rights_confirmed' => '1',
            'file' => $file ?? $this->pdfUpload(),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_uploads_carry_metadata_licence_and_an_honest_tier(): void
    {
        $this->actingAs($this->learner());
        $this->upload()->assertCreated()
            ->assertJsonPath('data.quality_tier', 'community')
            ->assertJsonPath('data.quality_label', 'Community contributed')
            ->assertJsonPath('data.licence_label', 'CC BY 4.0')
            ->assertJsonPath('data.country_code', 'ZA')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.status', 'published');

        $this->actingAs($this->approvedTutor());
        $this->upload(['title' => 'Tutor notes'])->assertCreated()->assertJsonPath('data.quality_tier', 'tutor_created');

        $this->upload(['rights_confirmed' => null])->assertUnprocessable()->assertJsonValidationErrors('rights_confirmed');
    }

    public function test_past_papers_need_an_official_source_link_and_a_review_before_anyone_else_sees_them(): void
    {
        $contributor = $this->learner('Contributor');
        $this->actingAs($contributor);

        $this->upload(['type' => 'past_paper', 'licence' => 'cc_by'])->assertUnprocessable()->assertJsonValidationErrors('licence');
        $this->upload(['type' => 'past_paper', 'ownership' => 'third_party', 'licence' => 'official_source'])->assertUnprocessable();
        $this->upload(['type' => 'past_paper', 'ownership' => 'third_party', 'licence' => 'official_source', 'source_url' => 'https://www.education.gov.za/Curriculum/NationalSeniorCertificate(NSC)Examinations/NSCPastExaminationpapers.aspx', 'is_free' => '0', 'price_minor' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors('is_free');

        $id = $this->upload([
            'title' => 'NSC Mathematics Paper 1, November 2023',
            'type' => 'past_paper',
            'ownership' => 'third_party',
            'licence' => 'official_source',
            'source_url' => 'https://www.education.gov.za/Curriculum/NationalSeniorCertificate(NSC)Examinations/NSCPastExaminationpapers.aspx',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');

        $other = $this->learner('Other learner');
        $this->actingAs($other)->getJson('/api/tutor/learning/library?type=past_paper')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/tutor/learning/library/{$id}")->assertNotFound();
        $this->get("/api/tutor/learning/library/{$id}/download")->assertNotFound();

        // Only an operator who is not the owner can record the review; learners cannot.
        $this->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'source_confirmed'])->assertForbidden();
        $operator = $this->operator();
        $this->actingAs($operator)->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'source_confirmed', 'notes' => 'Matches the DBE page.'])
            ->assertCreated()->assertJsonPath('data.quality_tier', 'officially_sourced')->assertJsonPath('data.status', 'published');

        $this->actingAs($other)->getJson("/api/tutor/learning/library/{$id}")->assertOk()
            ->assertJsonPath('data.quality_label', 'Officially sourced')
            ->assertJsonPath('data.reviewed_by.name', 'Olwethu Operator');
        $this->get("/api/tutor/learning/library/{$id}/download")->assertOk();
    }

    public function test_reviewed_exists_only_after_a_recorded_review(): void
    {
        $owner = $this->approvedTutor();
        $this->actingAs($owner);
        $id = $this->upload()->json('data.id');
        $this->assertSame('tutor_created', LibraryResource::find($id)->quality_tier->value);

        // Owners cannot review their own material, even as operators.
        $owner->forceFill(['is_platform_operator' => true])->save();
        $this->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'approved'])->assertForbidden();

        $this->actingAs($this->operator())->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'source_confirmed'])
            ->assertUnprocessable();
        $this->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'changes_requested'])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson("/api/tutor/learning/library/{$id}/reviews", ['outcome' => 'approved', 'notes' => 'Accurate and aligned to CAPS.'])
            ->assertCreated()->assertJsonPath('data.quality_tier', 'reviewed')->assertJsonPath('data.reviewer', 'Olwethu Operator');
    }

    public function test_licensing_rules_for_other_peoples_work_and_selling(): void
    {
        $this->actingAs($this->learner());

        $this->upload(['ownership' => 'third_party', 'licence' => 'all_rights_reserved', 'source_url' => 'https://example.com/book'])
            ->assertUnprocessable()->assertJsonValidationErrors('licence');
        $this->upload(['ownership' => 'third_party', 'licence' => 'cc_by_sa'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_url');
        $this->upload(['ownership' => 'third_party', 'licence' => 'cc_by_sa', 'source_url' => 'https://openstax.org/books/x', 'is_free' => '0', 'price_minor' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('is_free');
        $this->upload(['licence' => 'public_domain', 'is_free' => '0', 'price_minor' => 1000])->assertUnprocessable();
        $this->upload(['licence' => 'all_rights_reserved', 'is_free' => '0', 'price_minor' => 0])->assertUnprocessable()->assertJsonValidationErrors('price_minor');
        $this->upload(['ownership' => 'third_party', 'licence' => 'cc_by_sa', 'source_url' => 'https://openstax.org/books/x'])->assertCreated();
        $this->upload(['licence' => 'all_rights_reserved', 'is_free' => '0', 'price_minor' => 4500])->assertCreated()->assertJsonPath('data.price_minor', 4500);
    }

    public function test_paid_resources_download_only_for_the_owner_and_buyers(): void
    {
        $owner = $this->approvedTutor();
        $this->actingAs($owner);
        $paid = $this->upload(['licence' => 'all_rights_reserved', 'is_free' => '0', 'price_minor' => 4500])->json('data.id');
        $free = $this->upload(['title' => 'Free sheet'])->json('data.id');
        $this->get("/api/tutor/learning/library/{$paid}/download")->assertOk();

        $this->actingAs($this->learner());
        $this->getJson("/api/tutor/learning/library/{$paid}/download")->assertForbidden();
        $this->postJson("/api/tutor/learning/library/{$paid}/checkout", [], ['Idempotency-Key' => 'r-1'])->assertStatus(503);
        $download = $this->get("/api/tutor/learning/library/{$free}/download")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
    }

    public function test_a_copyright_notice_hides_the_resource_until_decided_and_an_upheld_file_cannot_return(): void
    {
        $owner = $this->learner('Owner');
        $file = $this->pdfUpload('textbook-scan.pdf');
        $bytes = file_get_contents($file->getRealPath());
        $this->actingAs($owner);
        $id = $this->upload([], $file)->json('data.id');

        $claimant = $this->learner('Publisher rep');
        $this->actingAs($claimant)->postJson("/api/tutor/learning/library/{$id}/reports", ['kind' => 'copyright', 'details' => 'This is a scan of our textbook chapter 4.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['claimant_name', 'work_reference', 'good_faith']);
        $reportId = $this->postJson("/api/tutor/learning/library/{$id}/reports", [
            'kind' => 'copyright',
            'details' => 'This is a scan of our textbook chapter 4.',
            'claimant_name' => 'Example Publishers (Pty) Ltd',
            'work_reference' => 'https://example.com/isbn/9780000000000',
            'good_faith' => '1',
        ])->assertCreated()->assertJsonPath('data.resource_status', 'taken_down')->json('data.id');

        $this->getJson("/api/tutor/learning/library/{$id}")->assertNotFound();

        // The owner answers; an operator dismisses: the resource returns.
        $this->actingAs($owner)->postJson("/api/tutor/learning/library/reports/{$reportId}/counter", ['statement' => 'I wrote these notes myself; they are not a scan of any textbook.'])->assertOk();
        $operator = $this->operator();
        $this->actingAs($operator)->getJson('/api/tutor/learning/library/moderation')->assertOk()->assertJsonPath('data.open_reports.0.status', 'countered');
        $this->postJson("/api/tutor/learning/library/reports/{$reportId}/decide", ['decision' => 'dismiss', 'notes' => 'Original work.'])
            ->assertOk()->assertJsonPath('data.resource_status', 'published');

        // A second notice is upheld: it stays down and the same file cannot be uploaded again.
        $second = $this->actingAs($claimant)->postJson("/api/tutor/learning/library/{$id}/reports", [
            'kind' => 'copyright', 'details' => 'New evidence: page 12 is copied verbatim.', 'claimant_name' => 'Example Publishers (Pty) Ltd',
            'work_reference' => 'https://example.com/isbn/9780000000000', 'good_faith' => '1',
        ])->json('data.id');
        $this->actingAs($operator)->postJson("/api/tutor/learning/library/reports/{$second}/decide", ['decision' => 'uphold', 'notes' => 'Verbatim copy.'])
            ->assertOk()->assertJsonPath('data.resource_status', 'taken_down');

        $this->actingAs($this->learner('Re-uploader'));
        $this->upload([], UploadedFile::fake()->createWithContent('renamed.pdf', $bytes))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'This file was taken down after a copyright complaint and cannot be uploaded again.');
        $this->assertSame(2, ResourceReport::query()->count());
    }

    public function test_reports_for_corrections_and_suggestions_do_not_hide_material(): void
    {
        $this->actingAs($this->learner('Owner'));
        $id = $this->upload()->json('data.id');
        $this->actingAs($this->learner('Reader'));
        $this->postJson("/api/tutor/learning/library/{$id}/reports", ['kind' => 'incorrect', 'details' => 'Example 3 has a sign error in step 2.'])
            ->assertCreated()->assertJsonPath('data.resource_status', 'published');
        $this->postJson("/api/tutor/learning/library/{$id}/reports", ['kind' => 'improvement', 'details' => 'Add a worked example on the chain rule.'])->assertCreated();
        $this->getJson('/api/tutor/learning/library/moderation')->assertForbidden();
    }

    public function test_search_and_filters_and_recommendations(): void
    {
        $owner = $this->approvedTutor();
        $this->actingAs($owner);
        $this->upload(['title' => 'Photosynthesis worksheet', 'type' => 'worksheet', 'subject_id' => $this->subject('Life Sciences')->id, 'topic' => 'Photosynthesis', 'language' => 'af', 'difficulty' => 'introductory'])->assertCreated();
        $calculus = $this->upload()->json('data.id');

        $reader = $this->learner();
        $this->actingAs($reader);
        $this->getJson('/api/tutor/learning/library?q=photosynthesis')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Photosynthesis worksheet');
        $this->getJson('/api/tutor/learning/library?type=summary')->assertJsonCount(1, 'data');
        $this->getJson('/api/tutor/learning/library?language=af&difficulty=introductory')->assertJsonCount(1, 'data');
        $this->getJson('/api/tutor/learning/library?tier=reviewed')->assertJsonCount(0, 'data');
        $this->getJson('/api/tutor/learning/library?country_code=ZA&subject_id='.$this->subject()->id)->assertJsonCount(1, 'data');

        $this->postJson("/api/tutor/learning/library/{$calculus}/recommend")->assertOk()->assertJsonPath('data.recommendations', 1);
        $this->postJson("/api/tutor/learning/library/{$calculus}/recommend")->assertOk()->assertJsonPath('data.recommendations', 1);
        $this->deleteJson("/api/tutor/learning/library/{$calculus}/recommend")->assertOk()->assertJsonPath('data.recommendations', 0);
        $this->actingAs($owner)->postJson("/api/tutor/learning/library/{$calculus}/recommend")->assertUnprocessable();
    }

    public function test_disguised_and_unaccepted_files_are_refused(): void
    {
        $this->actingAs($this->learner());
        $this->upload([], UploadedFile::fake()->createWithContent('notes.pdf', "#!/bin/sh\nrm -rf /\n"))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload([], UploadedFile::fake()->createWithContent('legacy.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600)))->assertUnprocessable();
        $this->upload([], $this->docxUpload())->assertCreated();
    }

    public function test_the_owner_can_withdraw_and_nobody_else_can(): void
    {
        $this->actingAs($this->learner('Owner'));
        $id = $this->upload()->json('data.id');
        $this->actingAs($this->learner('Other'))->postJson("/api/tutor/learning/library/{$id}/withdraw")->assertForbidden();
        $this->actingAs(LibraryResource::find($id)->owner)->postJson("/api/tutor/learning/library/{$id}/withdraw")->assertOk();
        $this->actingAs($this->learner('Third'))->getJson("/api/tutor/learning/library/{$id}")->assertNotFound();
    }
}
