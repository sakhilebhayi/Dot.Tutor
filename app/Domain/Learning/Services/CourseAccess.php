<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\MaterialPermission;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\Enrolment;
use App\Models\User;

/**
 * The one place that decides who may read what inside a course. Every controller that returns lesson content,
 * media or materials asks this service; nothing is protected only by hiding a link.
 */
class CourseAccess
{
    public function __construct(private EntitlementLookup $entitlements) {}

    public function isStaff(?User $user, Course $course): bool
    {
        return $user !== null && ($course->isOwnedBy($user) || (bool) $user->is_platform_operator);
    }

    /** Can this user see the course page (outline, price, objectives; never lesson content)? */
    public function canViewOutline(?User $user, Course $course): bool
    {
        if ($this->isStaff($user, $course)) {
            return true;
        }
        if ($course->status === CourseStatus::Published) {
            return true;
        }

        return $course->status === CourseStatus::Archived && $user !== null && $this->activeEnrolment($user, $course) !== null;
    }

    /** Has the user paid for (or been given) access, or is the course free? */
    public function hasPurchaseAccess(User $user, Course $course): bool
    {
        if ($course->isFree()) {
            return true;
        }
        if ($this->entitlements->owns($user, 'course', $course->id)) {
            return true;
        }
        foreach ($course->bundles()->pluck('learning_bundles.id') as $bundleId) {
            if ($this->entitlements->owns($user, 'bundle', (int) $bundleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An enrolment that still grants access. For a paid course the entitlement is re-checked on every request, so a
     * refund or revocation in the money domain ends access without waiting for a sync.
     */
    public function activeEnrolment(User $user, Course $course): ?Enrolment
    {
        $enrolment = Enrolment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
        if ($enrolment === null) {
            return null;
        }
        if ($enrolment->source === 'entitlement' && ! $this->hasPurchaseAccess($user, $course)) {
            return null;
        }

        // A learner who enrolled while the course was free keeps access if the creator later makes it paid.
        return $enrolment;
    }

    /**
     * Which published version (if any) this user reads lesson content from, and whether only previews are open.
     *
     * @return array{version: ?CourseVersion, preview_only: bool, enrolment: ?Enrolment}
     */
    public function readingContext(?User $user, Course $course): array
    {
        if ($user !== null && ($enrolment = $this->activeEnrolment($user, $course)) !== null) {
            return ['version' => $enrolment->version ?? $course->currentVersion, 'preview_only' => false, 'enrolment' => $enrolment];
        }
        if ($course->status === CourseStatus::Published) {
            return ['version' => $course->currentVersion, 'preview_only' => true, 'enrolment' => null];
        }

        return ['version' => null, 'preview_only' => true, 'enrolment' => null];
    }

    /**
     * The published lesson this user may read, or null. Staff use the draft preview routes instead.
     *
     * @return array<string, mixed>|null
     */
    public function readableLesson(?User $user, Course $course, int $lessonId): ?array
    {
        ['version' => $version, 'preview_only' => $previewOnly] = $this->readingContext($user, $course);
        $lesson = $version?->lesson($lessonId);
        if ($lesson === null) {
            return null;
        }
        if ($previewOnly && ! $lesson['is_preview']) {
            return null;
        }

        return $lesson;
    }

    /**
     * A material from the user's version that the user may fetch, or null.
     *
     * @return array<string, mixed>|null
     */
    public function readableMaterial(?User $user, Course $course, int $materialId): ?array
    {
        ['version' => $version, 'preview_only' => $previewOnly] = $this->readingContext($user, $course);
        foreach ($version?->snapshot['materials'] ?? [] as $material) {
            if ((int) $material['id'] !== $materialId) {
                continue;
            }
            if ($previewOnly && $material['permission'] !== MaterialPermission::PublicPreview->value) {
                return null;
            }

            return $material;
        }

        return null;
    }
}
