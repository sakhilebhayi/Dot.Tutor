<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\PaymentService;
use App\Domain\Marketplace\Services\ReviewService;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Domain\Money\Paystack\PaystackConfig;
use App\Domain\Student\Services\LearnerContext;
use App\Http\Controllers\Controller;
use App\Models\TutorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * One lesson from the learner's side (D-1110): status timeline, pay, cancel with the policy shown first, the
 * calendar file, the classroom link when the classroom exists, and the review once it is done. Every action is the
 * marketplace's own (BookingFlow, PaymentService, ReviewService); the policy decides who may see it.
 */
class LessonController extends Controller
{
    public function show(Request $request, TutorSession $tutorSession, BookingPresenter $presenter, ReviewService $reviews,
        PaystackConfig $paystack, LearnerContext $context): View
    {
        Gate::authorize('view', $tutorSession);
        $user = $request->user();
        $tutorSession->loadMissing(['tutorProfile.user', 'subject', 'offering']);
        $seat = $tutorSession->isGroup()
            ? TutorSessionParticipant::query()->where('tutor_session_id', $tutorSession->id)->where('learner_id', $user->id)->orderByDesc('id')->first()
            : null;
        $isLearner = $tutorSession->student_id === $user->id || $seat !== null;
        $data = $presenter->session($tutorSession, $user);
        if ($seat && ! isset($data['events'])) {
            $data['events'] = [];
        }

        $classroom = collect(['classroom.show', 'classroom.join', 'classroom.room'])->first(fn ($name) => Route::has($name));

        return view('student.lesson', [
            'ctx' => $context->for($user),
            'session' => $tutorSession,
            'data' => $data,
            'seat' => $seat ? $presenter->seat($seat, false) : null,
            'isLearner' => $isLearner,
            'cancelPreview' => $isLearner && ! $tutorSession->isGroup() ? $this->cancelPreview($tutorSession) : null,
            'canReview' => $isLearner && $reviews->canReview($tutorSession, $user),
            'myReview' => $tutorSession->reviews()->where('rated_by', $user->id)->first(),
            'paymentNotice' => $paystack->notice(),
            'testMode' => $paystack->isTestMode(),
            'classroomUrl' => $classroom && in_array($tutorSession->status, ['confirmed', 'in_progress'], true) ? route($classroom, $tutorSession) : null,
            'calendarUrl' => Route::has('comms.calendar.lesson') && in_array($tutorSession->status, ['confirmed', 'awaiting_payment', 'pending'], true) ? route('comms.calendar.lesson', $tutorSession->id) : null,
        ]);
    }

    public function pay(Request $request, TutorSession $tutorSession, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('view', $tutorSession);
        $user = $request->user();

        $item = $tutorSession->isGroup()
            ? TutorSessionParticipant::query()->where('tutor_session_id', $tutorSession->id)->where('learner_id', $user->id)->where('status', 'awaiting_payment')->firstOrFail()
            : $tutorSession;
        abort_unless($tutorSession->isGroup() || $tutorSession->student_id === $user->id, 403);

        // The same key the marketplace API uses when none is sent: one checkout per item, so a double click reuses it.
        $key = $item instanceof TutorSessionParticipant ? 'pay:tutor_session_participant:'.$item->id : 'pay:tutor_session:'.$item->id;

        return $this->toPayment($payments->start($item, $user, $key), route('learn.lessons.show', $tutorSession));
    }

    public function cancel(Request $request, TutorSession $tutorSession, BookingFlow $flow): RedirectResponse
    {
        Gate::authorize('view', $tutorSession);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $user = $request->user();

        if ($tutorSession->isGroup() && $tutorSession->tutorProfile->user_id !== $user->id) {
            $seat = TutorSessionParticipant::query()->where('tutor_session_id', $tutorSession->id)->where('learner_id', $user->id)
                ->whereIn('status', ['awaiting_guardian', 'awaiting_payment', 'confirmed'])->firstOrFail();
            $flow->cancelSeat($seat, $user, $reason);

            return redirect()->route('learn.lessons.show', $tutorSession)->with('status', 'Your seat is cancelled.');
        }

        $flow->cancel($tutorSession, $user, $reason);

        return redirect()->route('learn.lessons.show', $tutorSession)->with('status', 'The lesson is cancelled.');
    }

    public function review(Request $request, TutorSession $tutorSession, ReviewService $reviews): RedirectResponse
    {
        Gate::authorize('view', $tutorSession);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['nullable', 'string', 'max:2000'],
        ], ['rating.required' => 'Choose how many stars.']);

        $review = $reviews->review($tutorSession, $request->user(), (int) $data['rating'], $data['review'] ?? null);

        return redirect()->route('learn.lessons.show', $tutorSession)->with('status', $review->status === 'published'
            ? 'Thank you. Your review is published.'
            : 'Thank you. Your review is held for a quick check before it shows.');
    }

    /**
     * What cancelling now would mean for the learner. Mirrors the marketplace rule (BookingFlow::refundDue): nothing
     * paid, nothing due; inside the free-cancellation notice 100%; later, the offering's late percentage. The booking
     * records the real figure when it is cancelled.
     *
     * @return array{paid: bool, percent: int, minor: int, currency: string, rule: string}
     */
    private function cancelPreview(TutorSession $session): array
    {
        $paid = $session->payment_status === 'paid' ? (int) $session->price_minor : 0;
        $notice = $session->offering?->cancellation_notice_hours ?? 24;
        $hoursBefore = (int) floor(($session->starts_at->getTimestamp() - now()->getTimestamp()) / 3600);
        $percent = match (true) {
            $paid === 0 => 0,
            $hoursBefore >= $notice => 100,
            default => (int) ($session->offering?->late_cancellation_refund_percent ?? 0),
        };

        return [
            'paid' => $paid > 0,
            'percent' => $percent,
            'minor' => intdiv($paid * $percent, 100),
            'currency' => (string) $session->currency,
            'rule' => $paid === 0 ? 'nothing_paid' : ($hoursBefore >= $notice ? 'free' : 'late'),
        ];
    }

    private function toPayment(CheckoutResult $result, string $back): RedirectResponse
    {
        if ($result->status === 'pending' && $result->redirectUrl) {
            return redirect()->away($result->redirectUrl);
        }

        return redirect()->to($back)->with($result->status === 'paid' ? 'status' : 'error', $result->message);
    }
}
