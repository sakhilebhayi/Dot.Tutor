<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Services\TutorProfileService;
use App\Domain\Teach\Models\ReferralLink;
use App\Domain\Teach\Models\ReferralVisit;
use App\Domain\Teach\Services\AcademicOptions;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The storefront (brief section 18): what learners see, the share link, the referral link and a printable
 * promotional page. The live storefront page itself is the marketplace's /t/{id}; this screen edits and previews it.
 */
class StorefrontController extends Controller
{
    public function edit(Request $request, TutorPresenter $presenter, AcademicOptions $options): View
    {
        $profile = TutorContext::profile($request);
        $link = ReferralLink::for($profile);

        return view('teach.storefront', [
            'profile' => $profile,
            'store' => $presenter->storefront($profile),
            'courses' => $this->courses($profile),
            'materials' => $this->materials($profile),
            'shareUrl' => $profile->public_handle ? route('teach.handle', $profile->public_handle) : route('marketplace.tutors.show', $profile),
            'liveUrl' => route('marketplace.tutors.show', $profile),
            'referralUrl' => route('teach.referral', $link->code),
            'visits' => $link->visits()->count(),
            'visits30' => $link->visits()->where('visited_at', '>=', now()->subDays(30))->count(),
            'levelIndex' => $options->levelIndex(),
        ]);
    }

    public function update(Request $request, TutorProfileService $service): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:160'],
            'public_handle' => ['nullable', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', Rule::unique('tutor_profiles', 'public_handle')->ignore($profile->id)],
            'introduction' => ['required', 'string', 'min:40', 'max:5000'],
            'teaching_approach' => ['nullable', 'string', 'max:3000'],
        ], ['public_handle.regex' => 'Use lower-case letters, digits and dashes, starting and ending with a letter or digit.']);

        $service->update($profile, $data);

        return back()->with('success', 'Storefront saved.');
    }

    public function promo(Request $request, TutorPresenter $presenter): View
    {
        $profile = TutorContext::profile($request);

        return view('teach.promo', [
            'profile' => $profile,
            'store' => $presenter->storefront($profile),
            'courses' => $this->courses($profile),
            'link' => $profile->public_handle ? route('teach.handle', $profile->public_handle) : route('teach.referral', ReferralLink::for($profile)->code),
        ]);
    }

    /** The short share link /u/{handle}: opens the storefront. */
    public function handle(string $handle): RedirectResponse
    {
        $profile = TutorProfile::query()->where('public_handle', $handle)->firstOrFail();

        return redirect()->route('marketplace.tutors.show', $profile);
    }

    /**
     * A referral link: one visit is counted per signed-in visitor per day, then the storefront opens. Attribution
     * only: no credit, discount or money is attached (D-1209).
     */
    public function referral(Request $request, string $code): RedirectResponse
    {
        $link = ReferralLink::query()->where('code', $code)->firstOrFail();
        $visitor = $request->user();

        if ($visitor !== null && $visitor->id !== $link->tutorProfile?->user_id) {
            $already = ReferralVisit::query()->where('teach_referral_link_id', $link->id)->where('visitor_id', $visitor->id)
                ->where('visited_at', '>=', now()->startOfDay())->exists();
            if (! $already) {
                ReferralVisit::query()->create(['teach_referral_link_id' => $link->id, 'visitor_id' => $visitor->id, 'visited_at' => now()]);
            }
        }

        return redirect()->route('marketplace.tutors.show', $link->tutor_profile_id);
    }

    /** @return Collection<int, Course> */
    private function courses(TutorProfile $profile)
    {
        return $profile->user_id ? Course::query()->where('owner_id', $profile->user_id)->where('status', CourseStatus::Published->value)->orderBy('title')->get() : collect();
    }

    /** @return Collection<int, LibraryResource> */
    private function materials(TutorProfile $profile)
    {
        return $profile->user_id ? LibraryResource::query()->where('owner_id', $profile->user_id)->where('status', 'published')->orderBy('title')->limit(20)->get() : collect();
    }
}
