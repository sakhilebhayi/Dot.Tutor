<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\Idempotency;
use App\Domain\Marketplace\Services\PaymentService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Domain\Student\Services\LearnerContext;
use App\Domain\Student\Support\BookingWindow;
use App\Domain\Student\Support\Times;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\TutorSession;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Booking one offering (D-1108): a lesson at a real open time, a weekly series, a lesson package, or a seat in a
 * scheduled group lesson. Every request goes through the marketplace's BookingFlow (with its safeguarding, guardian,
 * clash and idempotency rules); this controller only collects the choice and explains the outcome.
 */
class BookController extends Controller
{
    private const LESSON_TYPES = ['one_to_one', 'consultation', 'homework_help', 'exam_prep', 'revision'];

    public function show(Request $request, int $offering, SlotFinder $slots, TutorPresenter $presenter, SafeguardingPort $gate, LearnerContext $context): View
    {
        $offering = $this->bookable($offering);
        $profile = $offering->tutorProfile;
        $user = $request->user();
        $type = $offering->typeEnum();

        $packages = TutorPackagePurchase::query()
            ->where('learner_id', $user->id)->where('tutor_offering_id', $offering->id)
            ->orderByDesc('id')->get();
        $usable = $packages->first(fn (TutorPackagePurchase $p) => $p->status === 'active' && $p->creditsLeft() > 0);

        $groupSessions = $type === OfferingType::Group
            ? TutorSession::query()->where('tutor_offering_id', $offering->id)->where('kind', TutorSession::KIND_GROUP)
                ->where('status', SessionStatus::Confirmed->value)->where('starts_at', '>', now())
                ->orderBy('starts_at')->limit(20)->get()
            : collect();
        $mySeats = $type === OfferingType::Group
            ? TutorSessionParticipant::query()->where('learner_id', $user->id)->whereIn('tutor_session_id', $groupSessions->pluck('id')->all() ?: [0])
                ->whereNotIn('status', ['cancelled'])->pluck('tutor_session_id')->all()
            : [];

        $needsTime = in_array($type->value, [...self::LESSON_TYPES, 'recurring_weekly'], true) || ($type === OfferingType::Package && $usable !== null);
        $duration = $usable?->lesson_minutes ?? $offering->duration_minutes;
        $days = $needsTime ? $slots->forTutor($profile, (int) $duration) : [];

        $decision = $gate->allows($user, 'book', $offering);
        $ctx = $context->for($user);

        // The learner's own window first (fix round D-2304): the search's days and hours, or after-school times for a
        // young learner; "Show all times" (?all=1) lists every open time. A chosen time outside the window shows all.
        $allDays = array_values(array_filter($days, fn ($d) => $d['slots'] !== []));
        $window = BookingWindow::fromRequest($request, ($ctx['step'] ?? 'standard') === 'young');
        $preselect = (string) $request->query('at', '');
        $windowDays = $window === null ? $allDays : array_values(array_filter(array_map(
            fn ($d) => ['slots' => array_values(array_filter($d['slots'], fn ($slot) => $window->contains(Times::local($slot))))] + $d,
            $allDays,
        ), fn ($d) => $d['slots'] !== []));
        $chosen = (string) $request->old('starts_at', $preselect);
        $chosenOutside = $chosen !== '' && ! collect($windowDays)->flatMap(fn ($d) => $d['slots'])
            ->contains(fn ($slot) => $slot->utc()->toIso8601String() === $chosen);
        $showAll = $window === null || $request->boolean('all') || $windowDays === [] || $chosenOutside;
        $countSlots = fn (array $list) => array_sum(array_map(fn ($d) => count($d['slots']), $list));

        return view('student.book', [
            'ctx' => $ctx,
            'offering' => $offering,
            'data' => $presenter->offering($offering),
            'tutor' => ['id' => $profile->id, 'name' => $profile->displayName(), 'verification' => $presenter->verification($profile), 'has_photo' => $profile->photo_path !== null],
            'type' => $type,
            'days' => $showAll ? $allDays : $windowDays,
            'window' => $window === null ? null : [
                'label' => $window->label(),
                'source' => $window->source,
                'applied' => ! $showAll,
                'empty' => $windowDays === [],
                'shown' => $countSlots($windowDays),
                'all' => $countSlots($allDays),
                'all_url' => route('learn.book', ['offering' => $offering->id] + $window->query() + ['all' => 1] + ($preselect !== '' ? ['at' => $preselect] : [])),
                'window_url' => route('learn.book', ['offering' => $offering->id] + $window->query()),
            ],
            'usablePackage' => $usable,
            'packages' => $packages,
            'groupSessions' => $groupSessions,
            'mySeats' => $mySeats,
            'blocked' => $decision->allowed ? null : $decision->reasons,
            'preselect' => $preselect,
        ]);
    }

    public function store(Request $request, int $offering, BookingFlow $flow, Idempotency $idempotency, PaymentService $payments): RedirectResponse
    {
        $offering = $this->bookable($offering);
        $user = $request->user();
        $type = $offering->typeEnum();
        $key = $this->key($request);

        if ($type === OfferingType::Package && ! $request->filled('starts_at')) {
            [$purchase, $replayed] = $idempotency->run($user, 'package:'.$offering->id, $key, ['offering_id' => $offering->id],
                function () use ($flow, $user, $offering) {
                    return $flow->buyPackage($user, $offering)[0];
                }, fn (int $id) => TutorPackagePurchase::query()->find($id));

            if ($purchase->status === 'awaiting_payment') {
                return $this->toPayment($payments->start($purchase, $user, 'pay:tutor_package_purchase:'.$purchase->id), route('learn.book', $offering->id));
            }

            return redirect()->route('learn.book', $offering->id)->with('flash.banner', $purchase->status === 'awaiting_guardian'
                ? 'Your parent or guardian has been asked to approve this package. You can pay once they agree.'
                : 'Package ready. Book its lessons below.');
        }

        if ($type === OfferingType::Group) {
            $data = $request->validate(['session_id' => ['required', 'integer']]);
            $session = TutorSession::query()->where('tutor_offering_id', $offering->id)->findOrFail($data['session_id']);
            [$seat, $replayed] = $idempotency->run($user, 'seat:'.$session->id, $key, ['session_id' => $session->id],
                fn () => $flow->requestSeat($user, $session)[0], fn (int $id) => TutorSessionParticipant::query()->find($id));

            return redirect()->route('learn.lessons.show', $session)->with('flash.banner', match ($seat->refresh()->status) {
                'awaiting_guardian' => 'Seat held. Your parent or guardian has been asked to approve it.',
                'awaiting_payment' => 'Seat held for you. Pay to keep it.',
                default => 'Your seat is booked.',
            });
        }

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'delivery' => ['nullable', 'in:online,in_person'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'weeks' => ['nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.recurring_weeks_max', 12)],
            'package_purchase_id' => ['nullable', 'integer'],
        ], [
            'starts_at.required' => 'Choose a time.',
            'subject_id.required' => 'Choose a subject.',
        ]);

        if ($type === OfferingType::RecurringWeekly) {
            $series = $idempotency->run($user, 'series:'.$offering->id, $key, $data,
                fn () => $flow->bookSeries($user, $offering, $data)[0],
                fn (int $id) => TutorBookingSeries::query()->find($id))[0];
            $first = $series->sessions()->orderBy('starts_at')->first();

            return redirect()->to($first ? route('learn.lessons.show', $first) : route('marketplace.bookings'))
                ->with('flash.banner', 'Weekly lessons requested: '.$series->sessions()->count().' '.Str::plural('lesson', $series->sessions()->count()).'. The tutor accepts the series once.');
        }

        $notice = null;
        [$session] = $idempotency->run($user, 'booking:'.$offering->id, $key, $data,
            function () use ($flow, $user, $offering, $data, &$notice) {
                [$session, $notice] = $flow->requestBooking($user, $offering, $data);

                return $session;
            }, fn (int $id) => TutorSession::query()->find($id));

        return redirect()->route('learn.lessons.show', $session)
            ->with('flash.banner', $notice ?? 'Request sent. '.app(BookingPresenter::class)->nextStep($session));
    }

    private function bookable(int $id): TutorOffering
    {
        $offering = TutorOffering::query()->with(['tutorProfile.user', 'subjects'])->findOrFail($id);
        abort_unless($offering->is_active && $offering->tutorProfile?->isApproved(), 404);

        return $offering;
    }

    /** A form-supplied key (one per page load) so a double click cannot create two bookings. */
    private function key(Request $request): ?string
    {
        $key = (string) $request->input('idempotency_key', '');

        return preg_match('/^[A-Za-z0-9._:\-]{8,120}$/', $key) ? $key : null;
    }

    private function toPayment(CheckoutResult $result, string $back): RedirectResponse
    {
        if ($result->status === 'pending' && $result->redirectUrl) {
            return redirect()->away($result->redirectUrl);
        }

        return redirect()->to($back)->with('flash.banner', $result->message)
            ->with('flash.bannerStyle', in_array($result->status, ['paid'], true) ? 'success' : 'danger');
    }
}
