<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Learning\Contracts\Seams\ContentReportSink;
use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Enums\QualityTier;
use App\Domain\Learning\Enums\ResourceLicence;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Enums\ResourceType;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReport;
use App\Domain\Learning\Models\ResourceReview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The resource library's rules: licensing and ownership, the past-paper rule, quality tiers that change only through a
 * recorded review, copyright notices with take-down and counter-notice, and who may download what.
 */
class LibraryService
{
    public function __construct(
        private FileIntake $files,
        private AcademicScopeLookup $academic,
        private TutorStanding $tutors,
        private SafeguardingCheck $safeguarding,
        private EntitlementLookup $entitlements,
        private ContentReportSink $reports,
        private AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated metadata
     */
    public function upload(User $owner, array $data, UploadedFile $file): LibraryResource
    {
        $type = ResourceType::from($data['type']);
        $licence = ResourceLicence::from($data['licence']);
        $ownership = $data['ownership'];
        $sourceUrl = $data['source_url'] ?? null;
        $isFree = (bool) ($data['is_free'] ?? true);
        $errors = [];

        $errors += $this->academic->validate($data['curriculum_id'] ?? null, $data['level_id'] ?? null, $data['subject_id'] ?? null, $data['topic_id'] ?? null);

        if ($licence === ResourceLicence::OfficialSource && ! $this->isHttps($sourceUrl)) {
            $errors['source_url'] = 'An officially sourced resource needs the https link to where the official body publishes it.';
        }
        if ($type === ResourceType::PastPaper) {
            if ($licence !== ResourceLicence::OfficialSource || ! $this->isHttps($sourceUrl)) {
                $errors['licence'] = 'Past examination papers are accepted only as "official source" with a link to the publisher (for example the Department of Basic Education\'s NSC past papers page). Exam papers without that are refused to protect copyright.';
            }
            if (! $isFree) {
                $errors['is_free'] = 'Past examination papers cannot be sold.';
            }
        }
        if ($ownership === 'third_party') {
            if ($licence === ResourceLicence::AllRightsReserved) {
                $errors['licence'] = 'Someone else\'s "all rights reserved" work cannot be shared here. Share it only if it carries an open licence, is public domain or is officially published for free use.';
            }
            if (! $this->isHttps($sourceUrl)) {
                $errors['source_url'] = 'Give the https link to the original so the author is credited.';
            }
            if (! $isFree) {
                $errors['is_free'] = 'Only your own work can be sold.';
            }
        }
        if (! $isFree) {
            if (in_array($licence, [ResourceLicence::PublicDomain, ResourceLicence::OfficialSource], true)) {
                $errors['is_free'] = 'Public-domain and officially sourced material must be free.';
            }
            if ((int) ($data['price_minor'] ?? 0) <= 0) {
                $errors['price_minor'] = 'Set a price above zero, or make the resource free.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $decision = $this->safeguarding->allows($owner, 'publish');
        if (! $decision->allowed) {
            throw ValidationException::withMessages(['file' => $decision->reason ?? 'Sharing material is not allowed for this account.']);
        }

        $stored = $this->files->accept($file, 'library', 'library');

        $blocked = LibraryResource::query()->where('sha256', $stored->sha256)
            ->where('status', ResourceStatus::TakenDown->value)
            ->whereHas('reports', fn ($q) => $q->where('kind', 'copyright')->where('status', 'upheld'))
            ->exists();
        if ($blocked) {
            \Illuminate\Support\Facades\Storage::disk('learning')->delete($stored->path);
            throw ValidationException::withMessages(['file' => 'This file was taken down after a copyright complaint and cannot be uploaded again.']);
        }

        $asTutor = $this->tutors->isApprovedTutor($owner);
        $resource = LibraryResource::create([
            'owner_id' => $owner->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $type,
            'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
            'curriculum_id' => $data['curriculum_id'] ?? null,
            'level_id' => $data['level_id'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'topic_id' => $data['topic_id'] ?? null,
            'topic' => $data['topic'] ?? null,
            'language' => $data['language'] ?? 'en',
            'difficulty' => $data['difficulty'] ?? null,
            'institution' => $data['institution'] ?? null,
            'ownership' => $ownership,
            'licence' => $licence,
            'source_url' => $sourceUrl,
            'contributed_as_tutor' => $asTutor,
            'is_free' => $isFree,
            'price_minor' => $isFree ? null : (int) $data['price_minor'],
            'currency' => $data['currency'] ?? config('tutor.currency', 'ZAR'),
            // Past papers wait for a reviewer to confirm the official source before anyone else can see them.
            'status' => $type === ResourceType::PastPaper ? ResourceStatus::PendingReview : ResourceStatus::Published,
            'quality_tier' => $asTutor ? QualityTier::TutorCreated : QualityTier::Community,
            'path' => $stored->path,
            'mime' => $stored->mime,
            'size' => $stored->size,
            'sha256' => $stored->sha256,
            'original_name' => $stored->originalName,
        ]);
        $this->audit->record($owner, 'learning.resource.uploaded', $resource, ['licence' => $licence->value, 'type' => $type->value]);

        return $resource;
    }

    /** Record a review. Only this can make a resource "reviewed" or "officially sourced". */
    public function review(User $reviewer, LibraryResource $resource, string $outcome, ?string $notes): ResourceReview
    {
        if ($resource->isOwnedBy($reviewer)) {
            throw ValidationException::withMessages(['outcome' => 'You cannot review your own resource.']);
        }
        if ($outcome === 'source_confirmed' && $resource->licence !== ResourceLicence::OfficialSource) {
            throw ValidationException::withMessages(['outcome' => 'Only a resource declared as "official source" can have its source confirmed.']);
        }

        return DB::transaction(function () use ($reviewer, $resource, $outcome, $notes) {
            $review = ResourceReview::create([
                'resource_id' => $resource->id,
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'outcome' => $outcome,
                'notes' => $notes,
            ]);

            $updates = match ($outcome) {
                'source_confirmed' => ['quality_tier' => QualityTier::OfficiallySourced, 'status' => ResourceStatus::Published],
                'approved' => $resource->type === ResourceType::PastPaper
                    ? [] // A past paper is published only by confirming its official source.
                    : ['quality_tier' => $resource->quality_tier === QualityTier::OfficiallySourced ? QualityTier::OfficiallySourced : QualityTier::Reviewed],
                'rejected' => ['status' => ResourceStatus::TakenDown, 'takedown_reason' => $notes ?: 'Rejected in review.', 'taken_down_at' => now()],
                default => [],
            };
            if ($updates !== []) {
                $resource->forceFill($updates)->save();
            }
            $this->audit->record($reviewer, 'learning.resource.reviewed', $resource, ['outcome' => $outcome]);

            return $review;
        });
    }

    /**
     * File a report. A complete copyright notice hides the resource at once (pending an operator decision), which is
     * how a take-down notification is handled under South Africa's ECT Act section 77 (desk reading, not legal advice).
     *
     * @param  array<string, mixed>  $data
     */
    public function report(User $reporter, LibraryResource $resource, array $data): ResourceReport
    {
        $report = ResourceReport::create([
            'resource_id' => $resource->id,
            'reporter_id' => $reporter->id,
            'kind' => $data['kind'],
            'details' => $data['details'],
            'claimant_name' => $data['claimant_name'] ?? null,
            'work_reference' => $data['work_reference'] ?? null,
            'status' => 'open',
        ]);
        $report->forceFill(['forwarded_reference' => $this->reports->forward($reporter, $resource, $data['kind'], $data['details'])])->save();

        if ($data['kind'] === 'copyright' && $resource->status === ResourceStatus::Published) {
            $resource->forceFill([
                'status' => ResourceStatus::TakenDown,
                'takedown_reason' => 'Hidden after a copyright notice, pending review.',
                'taken_down_at' => now(),
            ])->save();
        }
        $this->audit->record($reporter, 'learning.resource.reported', $resource, ['kind' => $data['kind'], 'report_id' => $report->id]);

        return $report;
    }

    /** The owner answers a copyright notice; the operator decides again. */
    public function counterNotice(User $owner, ResourceReport $report, string $statement): ResourceReport
    {
        if (! $report->resource->isOwnedBy($owner) || $report->kind !== 'copyright' || ! in_array($report->status, ['open', 'upheld'], true)) {
            throw ValidationException::withMessages(['counter_notice' => 'A counter-notice can only answer an open or upheld copyright notice on your own resource.']);
        }
        $report->forceFill(['status' => 'countered', 'counter_notice' => $statement])->save();
        $this->audit->record($owner, 'learning.resource.counter_notice', $report->resource, ['report_id' => $report->id]);

        return $report;
    }

    /** An operator upholds (keeps it down) or dismisses (restores it) a report. */
    public function decide(User $operator, ResourceReport $report, string $decision, ?string $notes): ResourceReport
    {
        $resource = $report->resource;
        DB::transaction(function () use ($operator, $report, $decision, $notes, $resource) {
            $report->forceFill([
                'status' => $decision === 'uphold' ? 'upheld' : 'dismissed',
                'decided_by' => $operator->id,
                'decision_notes' => $notes,
                'decided_at' => now(),
            ])->save();

            if ($decision === 'uphold' && in_array($report->kind, ['copyright', 'incorrect'], true)) {
                $resource->forceFill(['status' => ResourceStatus::TakenDown, 'takedown_reason' => $notes ?: 'Report upheld.', 'taken_down_at' => $resource->taken_down_at ?? now()])->save();
            }
            if ($decision === 'dismiss' && $report->kind === 'copyright' && $resource->status === ResourceStatus::TakenDown) {
                $stillOpen = $resource->reports()->where('kind', 'copyright')->whereIn('status', ['open', 'upheld', 'countered'])->where('id', '!=', $report->id)->exists();
                if (! $stillOpen) {
                    $resource->forceFill(['status' => ResourceStatus::Published, 'takedown_reason' => null, 'taken_down_at' => null])->save();
                }
            }
        });
        $this->audit->record($operator, 'learning.resource.report_decided', $resource, ['report_id' => $report->id, 'decision' => $decision]);

        return $report->fresh();
    }

    public function canSee(?User $user, LibraryResource $resource): bool
    {
        if ($resource->status === ResourceStatus::Published) {
            return true;
        }

        return $user !== null && ($resource->isOwnedBy($user) || (bool) $user->is_platform_operator);
    }

    public function canDownload(User $user, LibraryResource $resource): bool
    {
        if ($resource->isOwnedBy($user) || $user->is_platform_operator) {
            return true;
        }
        if ($resource->status !== ResourceStatus::Published) {
            return false;
        }

        return $resource->is_free || $this->entitlements->owns($user, 'resource', $resource->id);
    }

    private function isHttps(?string $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }
}
