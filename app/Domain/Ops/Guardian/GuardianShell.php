<?php

namespace App\Domain\Ops\Guardian;

use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Safety\Models\GuardianLink;
use App\Models\User;
use App\View\ShellNavigation;
use Illuminate\Support\Facades\Route;

/**
 * Which navigation the learner frame shows (D-2100). A parent should not be handed "Find a tutor", "Practice" and
 * "Study assistant": a person with an active guardian link gets the guardian navigation (config/tutor_nav.php
 * 'guardian': Children, Approvals, Safety, Messages, Payments) on every guardian page, and everywhere in the
 * learner frame when they have no learning profile of their own (the same rule RoleHome uses to land them on
 * /guardian). A guardian who also learns keeps the learner navigation on learning pages, with "Guardian" in it,
 * and gets "Your learning" in the guardian navigation.
 */
class GuardianShell
{
    private ?bool $guardian = null;

    private ?bool $learner = null;

    public function __construct(private readonly ShellNavigation $navigation) {}

    public function applies(?User $user = null): bool
    {
        $user ??= request()->user();
        if ($user === null || ! $this->isGuardian($user)) {
            return false;
        }

        return request()->routeIs('guardian.*', 'safety.guardian.dashboard') || ! $this->isLearner($user);
    }

    /**
     * The frame's scope label, navigation and phone bar for the learner layout.
     *
     * @return array{scope: string, nav: array<int, mixed>, bottom: array<int, mixed>}
     */
    public function frame(string $step): array
    {
        if (! $this->applies()) {
            return [
                'scope' => 'Learning',
                'nav' => $this->navigation->for('learner', $step),
                'bottom' => $this->navigation->bottomBar('learner', $step),
            ];
        }

        $nav = $this->navigation->for('guardian', $step);
        if (! $this->isLearner(request()->user()) && Route::has('learn.home')) {
            $learnHome = route('learn.home');
            $nav = collect($nav)->map(function (array $section) use ($learnHome) {
                $section['items'] = array_values(array_filter($section['items'], fn (array $item) => $item['href'] !== $learnHome));

                return $section;
            })->filter(fn (array $section) => $section['items'] !== [])->values()->all();
        }

        return ['scope' => 'Guardian', 'nav' => $nav, 'bottom' => $this->navigation->bottomBar('guardian', $step)];
    }

    private function isGuardian(User $user): bool
    {
        return $this->guardian ??= class_exists(GuardianLink::class)
            && GuardianLink::query()->where('guardian_id', $user->id)->active()->exists();
    }

    private function isLearner(?User $user): bool
    {
        return $this->learner ??= $user !== null && class_exists(LearnerProfile::class)
            && LearnerProfile::query()->where('user_id', $user->id)->exists();
    }
}
