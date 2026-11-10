<?php

namespace App\Domain\Marketplace\Http\Controllers\Api;

use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\Idempotency;
use App\Domain\Marketplace\Services\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Booking v2 over JSON. Every create accepts an Idempotency-Key header: a
 * retry with the same key and body returns the original (200, replayed:
 * true) and books nothing twice.
 */
class BookingController extends Controller
{
    public function __construct(private BookingFlow $flow, private BookingPresenter $presenter, private Idempotency $idempotency) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profileId = $user->tutorProfile()->value('id');

        $asLearner = TutorSession::query()->where('student_id', $user->id)->orderByDesc('starts_at')->limit(100)->get();
        $asTutor = $profileId ? TutorSession::query()->where('tutor_profile_id', $profileId)->orderByDesc('starts_at')->limit(100)->get() : collect();
        $seats = TutorSessionParticipant::query()->where('learner_id', $user->id)->with('session')->orderByDesc('id')->limit(100)->get();
        $packages = TutorPackagePurchase::query()->where('learner_id', $user->id)->orderByDesc('id')->get();

        return response()->json(['data' => [
            'as_learner' => $asLearner->map(fn ($s) => $this->presenter->session($s, $user))->all(),
            'seats' => $seats->map(fn ($p) => $this->presenter->seat($p))->all(),
            'packages' => $packages->map(fn ($p) => $this->presenter->package($p))->all(),
            'as_tutor' => $asTutor->map(fn ($s) => $this->presenter->session($s, $user))->all(),
        ]]);
    }

    public function show(Request $request, TutorSession $tutorSession): JsonResponse
    {
        Gate::authorize('view', $tutorSession);

        return response()->json(['data' => $this->presenter->session($tutorSession, $request->user())]);
    }

    public function store(Request $request, TutorOffering $offering): JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'delivery' => ['nullable', 'in:online,in_person'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'package_purchase_id' => ['nullable', 'integer'],
        ]);

        $notice = null;
        [$session, $replayed] = $this->idempotency->run(
            $request->user(), 'booking:'.$offering->id, $request->header('Idempotency-Key'), $data,
            function () use ($request, $offering, $data, &$notice) {
                [$session, $notice] = $this->flow->requestBooking($request->user(), $offering, $data);

                return $session;
            },
            fn (int $id) => TutorSession::query()->find($id),
        );

        return response()->json([
            'data' => $this->presenter->session($session, $request->user()),
            'notice' => $notice,
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function storeSeries(Request $request, TutorOffering $offering): JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'weeks' => ['nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.recurring_weeks_max')],
            'delivery' => ['nullable', 'in:online,in_person'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location_note' => ['nullable', 'string', 'max:200'],
        ]);

        $skipped = [];
        $notice = null;
        [$series, $replayed] = $this->idempotency->run(
            $request->user(), 'series:'.$offering->id, $request->header('Idempotency-Key'), $data,
            function () use ($request, $offering, $data, &$skipped, &$notice) {
                [$series, $skipped, $notice] = $this->flow->bookSeries($request->user(), $offering, $data);

                return $series;
            },
            fn (int $id) => TutorBookingSeries::query()->find($id),
        );

        return response()->json([
            'data' => $this->presenter->series($series->load('sessions'), $request->user()),
            'skipped' => $skipped,
            'notice' => $notice,
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function storePackage(Request $request, TutorOffering $offering): JsonResponse
    {
        $notice = null;
        [$purchase, $replayed] = $this->idempotency->run(
            $request->user(), 'package:'.$offering->id, $request->header('Idempotency-Key'), ['offering_id' => $offering->id],
            function () use ($request, $offering, &$notice) {
                [$purchase, $notice] = $this->flow->buyPackage($request->user(), $offering);

                return $purchase;
            },
            fn (int $id) => TutorPackagePurchase::query()->find($id),
        );

        return response()->json(['data' => $this->presenter->package($purchase), 'notice' => $notice, 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function storeSeat(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $notice = null;
        [$seat, $replayed] = $this->idempotency->run(
            $request->user(), 'seat:'.$tutorSession->id, $request->header('Idempotency-Key'), ['session_id' => $tutorSession->id],
            function () use ($request, $tutorSession, &$notice) {
                [$seat, $notice] = $this->flow->requestSeat($request->user(), $tutorSession);

                return $seat;
            },
            fn (int $id) => TutorSessionParticipant::query()->find($id),
        );

        return response()->json(['data' => $this->presenter->seat($seat->refresh()), 'notice' => $notice, 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    // Actions ------------------------------------------------------------

    public function accept(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->respond($request, $this->flow->accept($tutorSession, $request->user(), $data['note'] ?? null));
    }

    public function decline(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond($request, $this->flow->decline($tutorSession, $request->user(), $data['reason']));
    }

    public function cancel(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->respond($request, $this->flow->cancel($tutorSession, $request->user(), $data['reason'] ?? null));
    }

    public function start(Request $request, TutorSession $tutorSession): JsonResponse
    {
        return $this->respond($request, $this->flow->start($tutorSession, $request->user()));
    }

    public function complete(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $done = $this->flow->complete($tutorSession, $request->user());

        return response()->json(['data' => $this->presenter->session($tutorSession->refresh(), $request->user()), 'already_completed' => ! $done]);
    }

    public function noShow(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->respond($request, $this->flow->markNoShow($tutorSession, $request->user(), $data['reason'] ?? null));
    }

    public function dispute(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->respond($request, $this->flow->dispute($tutorSession, $request->user(), $data['reason']));
    }

    public function acceptSeries(Request $request, TutorBookingSeries $series): JsonResponse
    {
        $accepted = $this->flow->acceptSeries($series, $request->user());

        return response()->json(['data' => $this->presenter->series($series->load('sessions'), $request->user()), 'accepted' => $accepted]);
    }

    public function cancelSeat(Request $request, TutorSessionParticipant $seat): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $seat = $this->flow->cancelSeat($seat, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->seat($seat)]);
    }

    // Payment -------------------------------------------------------------

    public function pay(Request $request, TutorSession $tutorSession, PaymentService $payments): JsonResponse
    {
        return $this->startPayment($request, $tutorSession, $payments);
    }

    public function paySeat(Request $request, TutorSessionParticipant $seat, PaymentService $payments): JsonResponse
    {
        return $this->startPayment($request, $seat, $payments);
    }

    public function payPackage(Request $request, TutorPackagePurchase $purchase, PaymentService $payments): JsonResponse
    {
        return $this->startPayment($request, $purchase, $payments);
    }

    private function startPayment(Request $request, TutorSession|TutorSessionParticipant|TutorPackagePurchase $item, PaymentService $payments): JsonResponse
    {
        // The money domain de-duplicates checkouts by this key; a client that sends none gets one per item.
        $key = $request->header('Idempotency-Key') ?: 'pay:'.Str::snake(class_basename($item)).':'.$item->getKey();
        $result = $payments->start($item, $request->user(), $key);

        if ($result->status === 'unconfigured') {
            return response()->json(['error' => 'payments_not_configured', 'message' => $result->message, 'payment' => $result->toArray()], 503);
        }

        return response()->json(['payment' => $result->toArray()]);
    }

    private function respond(Request $request, TutorSession $session): JsonResponse
    {
        return response()->json(['data' => $this->presenter->session($session, $request->user())]);
    }
}
