<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\ReviewService;
use App\Domain\Ops\Access\OpsPermission as P;
use App\Enums\SessionStatus;
use App\Models\SessionRating;
use App\Models\TutorSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reviews and disputes (brief sections 16, 17 and 20): disputed lessons first (support and finance resolve them
 * through the marketplace BookingFlow; any refund due becomes a finance item), held tutor reviews (moderators,
 * through ReviewService), and every booking with its timeline.
 */
class ReviewsController extends OpsController
{
    public function index(): View
    {
        $canBookings = $this->can(P::BOOKINGS_VIEW);
        $canContent = $this->can(P::CONTENT_MODERATE);

        return view('ops.reviews.index', [
            'disputes' => $canBookings ? TutorSession::query()->with(['tutorProfile.user', 'student', 'subject'])
                ->where('status', SessionStatus::Disputed->value)->orderBy('updated_at')->limit(100)->get() : null,
            'held' => $canContent ? SessionRating::query()->with(['session.tutorProfile.user', 'rater'])
                ->where('status', ReviewStatus::Held->value)->orderBy('updated_at')->limit(100)->get() : null,
            'reportCounts' => $canContent ? DB::table('tutor_review_reports')->select('session_rating_id', DB::raw('count(*) as n'))->groupBy('session_rating_id')->pluck('n', 'session_rating_id') : collect(),
            'canResolve' => $this->can(P::BOOKINGS_RESOLVE),
        ]);
    }

    public function bookings(Request $request): View
    {
        $status = in_array($request->query('status'), SessionStatus::values(), true) ? $request->query('status') : null;

        return view('ops.reviews.bookings', [
            'status' => $status,
            'bookings' => TutorSession::query()->with(['tutorProfile.user', 'student', 'subject'])
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('starts_at')->paginate(30)->withQueryString(),
        ]);
    }

    public function booking(TutorSession $tutorSession): View
    {
        $tutorSession->load(['tutorProfile.user', 'student', 'subject', 'offering']);

        return view('ops.reviews.booking', [
            'booking' => $tutorSession,
            'events' => $tutorSession->events()->orderBy('id')->get(),
            'actors' => DB::table('users')->whereIn('id', $tutorSession->events()->pluck('actor_id')->filter()->unique())->pluck('name', 'id'),
            'review' => SessionRating::query()->where('session_id', $tutorSession->id)->get(),
            'orders' => DB::table('money_order_lines')->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
                ->where('money_order_lines.purchasable_type', 'booking')->where('money_order_lines.purchasable_id', $tutorSession->id)
                ->select('money_orders.uuid', 'money_orders.status', 'money_order_lines.cash_minor', 'money_order_lines.refunded_minor', 'money_orders.currency')->get(),
            'canResolve' => $this->can(P::BOOKINGS_RESOLVE),
            'canFinance' => $this->can(P::FINANCE_VIEW),
        ]);
    }

    public function moderate(Request $request, SessionRating $review, ReviewService $reviews): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['published', 'removed'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $reviews->moderate($review, $request->user(), ReviewStatus::from($data['to']), $data['reason']);
        } catch (MarketplaceException $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done($data['to'] === 'published' ? 'Review published; it now counts in the tutor\'s rating.' : 'Review removed; it no longer counts.');
    }

    public function resolve(Request $request, TutorSession $tutorSession, BookingFlow $flow): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['completed', 'cancelled'])],
            'refund_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $flow->resolveDispute($tutorSession, $request->user(), $data['outcome'], (int) $data['refund_percent'], $data['reason']);
        } catch (MarketplaceException $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done((int) $data['refund_percent'] > 0
            ? 'Dispute resolved. The refund due is now a finance item; finance sends it through Paystack.'
            : 'Dispute resolved with no refund.');
    }
}
