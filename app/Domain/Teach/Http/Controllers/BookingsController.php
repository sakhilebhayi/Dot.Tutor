<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\Idempotency;
use App\Domain\Teach\Services\BookingQueries;
use App\Domain\Teach\Support\TutorContext;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Requests and bookings (brief sections 7 and 25 tutor journey): answer requests with reasons, accept weekly series,
 * run lessons (start, complete, no-show), cancel under the offering's terms, schedule group lessons with seats and see
 * the packages learners bought. Every action goes through the marketplace BookingFlow, which checks the state and
 * the actor again on the server.
 */
class BookingsController extends Controller
{
    public const VIEWS = ['requests', 'upcoming', 'waiting', 'groups', 'packages', 'past', 'cancelled'];

    public function index(Request $request, BookingQueries $bookings): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'requests';
        $now = CarbonImmutable::now();

        $counts = [
            'requests' => $bookings->base($profile)->where('status', SessionStatus::Pending->value)->count(),
            'waiting' => $bookings->base($profile)->where('status', SessionStatus::AwaitingPayment->value)->count(),
        ];

        $list = match ($view) {
            // Paged like every other view: a busy tutor can hold over a hundred open requests (R-47 D-1606).
            'requests' => $bookings->base($profile)->where('status', SessionStatus::Pending->value)->whereNull('tutor_booking_series_id')
                ->orderBy('starts_at')->paginate(25)->withQueryString(),
            'upcoming' => $bookings->base($profile)->whereIn('status', ['confirmed', 'in_progress'])->where('kind', '!=', 'group')
                ->where('starts_at', '>=', $now->subHours(6))->orderBy('starts_at')->paginate(25)->withQueryString(),
            'waiting' => $bookings->base($profile)->where('status', 'awaiting_payment')->orderBy('starts_at')->paginate(25)->withQueryString(),
            'groups' => $bookings->base($profile)->where('kind', 'group')->whereIn('status', ['confirmed', 'in_progress'])
                ->with(['participants' => fn ($q) => $q->where('status', '!=', 'awaiting_guardian'), 'participants.learner:id,name'])->orderBy('starts_at')->paginate(25)->withQueryString(),
            'past' => $bookings->base($profile)->whereIn('status', ['completed', 'no_show', 'disputed'])->orderByDesc('starts_at')->paginate(25)->withQueryString(),
            'cancelled' => $bookings->base($profile)->where('status', 'cancelled')->orderByDesc('starts_at')->paginate(25)->withQueryString(),
            default => collect(),
        };

        return view('teach.bookings', [
            'profile' => $profile,
            'tz' => $tz,
            'view' => $view,
            'counts' => $counts,
            'guardianHolds' => $bookings->guardianHoldCount($profile),
            'list' => $list,
            'series' => $view === 'requests' ? $bookings->pendingSeries($profile) : collect(),
            'packages' => $view === 'packages'
                ? TutorPackagePurchase::query()->where('tutor_profile_id', $profile->id)->with(['learner:id,name', 'offering:id,title'])->orderByDesc('id')->paginate(25)->withQueryString()
                : null,
            'groupOfferings' => $view === 'groups' ? $profile->offerings()->where('type', 'group')->where('is_active', true)->with('subjects:id,name')->get() : collect(),
        ]);
    }

    public function act(Request $request, TutorSession $tutorSession, string $action, BookingFlow $flow): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        abort_unless($tutorSession->tutor_profile_id === $profile->id, 404);
        $user = $request->user();

        $reason = match ($action) {
            'decline' => $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Give the learner a reason for declining.'])['reason'],
            'cancel' => $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Say why you are cancelling; the learner sees it.'])['reason'],
            'no-show' => $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null,
            default => null,
        };

        try {
            match ($action) {
                'accept' => $flow->accept($tutorSession, $user),
                'decline' => $flow->decline($tutorSession, $user, $reason),
                'cancel' => $flow->cancel($tutorSession, $user, $reason),
                'start' => $flow->start($tutorSession, $user),
                'complete' => $flow->complete($tutorSession, $user),
                'no-show' => $flow->markNoShow($tutorSession, $user, $reason),
            };
        } catch (MarketplaceException $e) {
            return back()->with('error', $e->getMessage());
        }

        $fresh = $tutorSession->fresh();
        $message = match ($action) {
            'accept' => $fresh?->status === SessionStatus::AwaitingPayment->value
                ? 'Accepted. The learner has been asked to pay; the time is held until then.'
                : 'Accepted and confirmed.',
            'decline' => 'Declined. The learner sees your reason.',
            'cancel' => 'Cancelled. Any refund due follows the offering\'s terms; when a tutor cancels, the learner is refunded in full.',
            'start' => 'Lesson started.',
            'complete' => 'Marked complete. The earning becomes available after the complaint window.',
            'no-show' => 'Recorded as a no-show. Finance reviews any payment.',
        };

        return back()->with('success', $message);
    }

    public function acceptSeries(Request $request, TutorBookingSeries $series, BookingFlow $flow): RedirectResponse
    {
        abort_unless($series->tutor_profile_id === TutorContext::profile($request)->id, 404);

        try {
            $count = $flow->acceptSeries($series, $request->user());
        } catch (MarketplaceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Accepted {$count} weekly ".Str::plural('lesson', $count).'.');
    }

    /** Schedule a group lesson from one of the tutor's group offerings; learners then take seats. */
    public function scheduleGroup(Request $request, TutorOffering $offering, BookingFlow $flow, Idempotency $idempotency): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        abort_unless($offering->tutor_profile_id === $profile->id, 404);

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], TutorContext::tz($profile))->utc();
        $payload = ['subject_id' => (int) $data['subject_id'], 'starts_at' => $startsAt->toIso8601String(), 'location_note' => $data['location_note'] ?? null, 'notes' => $data['notes'] ?? null];

        try {
            $idempotency->run(
                $request->user(), 'group_schedule:'.$offering->id, $data['idempotency_key'], $payload,
                fn () => $flow->scheduleGroup($request->user(), $offering, $payload),
                fn (int $id) => TutorSession::query()->find($id),
            );
        } catch (MarketplaceException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('teach.bookings', ['view' => 'groups'])->with('success', 'Group lesson scheduled. Learners can now take seats.');
    }
}
