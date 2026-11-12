<?php

namespace App\Domain\Student\Http\Composers;

use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingPresenter;
use Illuminate\View\View;

/** Group seats and lesson packages for the lessons screen (marketplace /my/bookings), read-only (D-1110). */
class LessonsComposer
{
    public function __construct(private readonly BookingPresenter $presenter) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $view->with([
            'seats' => TutorSessionParticipant::query()->with(['session.tutorProfile', 'session.subject'])
                ->where('learner_id', $user->id)->orderByDesc('id')->limit(50)->get(),
            'packages' => TutorPackagePurchase::query()->where('learner_id', $user->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($p) => $this->presenter->package($p) + ['tutor_name' => $p->tutorProfile?->displayName()]),
        ]);
    }
}
