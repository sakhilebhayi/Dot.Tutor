<?php

namespace App\Domain\Student\Http\Composers;

use App\Domain\Academic\Models\Language;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Services\Scheduling\SlotFinder;
use Illuminate\View\View;

/**
 * The storefront (marketplace /t/{id}) shows what the marketplace presenter leaves as "not connected": the tutor's
 * published courses and library materials (learning), the text of published reviews, and the next open times from
 * the real slot finder (D-1109). Read-only.
 */
class StorefrontComposer
{
    public function __construct(private readonly SlotFinder $slots) {}

    public function compose(View $view): void
    {
        /** @var TutorProfile $profile */
        $profile = $view->getData()['profile'];
        $userId = $profile->user_id;

        $days = $this->slots->forTutor($profile, 60, null, 14);
        $next = [];
        foreach ($days as $day) {
            foreach ($day['slots'] as $slot) {
                $next[] = $slot;
                if (count($next) >= 6) {
                    break 2;
                }
            }
        }

        $view->with([
            'courses' => $userId ? Course::query()->with('currentVersion')->where('owner_id', $userId)->where('status', CourseStatus::Published->value)
                ->orderByDesc('published_at')->limit(6)->get() : collect(),
            'materials' => $userId ? LibraryResource::query()->where('owner_id', $userId)->where('status', ResourceStatus::Published->value)
                ->orderByDesc('id')->limit(6)->get() : collect(),
            'reviewTexts' => SessionRating::query()
                ->join('tutor_sessions', 'tutor_sessions.id', '=', 'session_ratings.session_id')
                ->where('tutor_sessions.tutor_profile_id', $profile->id)
                ->where('session_ratings.status', ReviewStatus::Published->value)
                ->whereNotNull('session_ratings.review')
                ->orderByDesc('session_ratings.id')->limit(5)
                ->get(['session_ratings.id', 'session_ratings.rating', 'session_ratings.review', 'session_ratings.tutor_reply', 'session_ratings.created_at']),
            'nextOpen' => $next,
            'languageNames' => Language::query()->pluck('name_native', 'code'),
        ]);
    }
}
