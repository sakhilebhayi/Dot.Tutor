<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\CourseAccess;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Learning\Support\CoursePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The learner-facing catalogue: course pages, price quotes, checkout hand-off and enrolment. */
class CatalogueController extends LearningController
{
    public function __construct(
        private CoursePresenter $presenter,
        private CourseAccess $access,
        private CheckoutGateway $checkout,
        private SafeguardingCheck $safeguarding,
    ) {}

    public function index(Request $request): JsonResponse|View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'pricing' => ['nullable', 'in:free,paid'],
            'language' => ['nullable', 'string', 'max:20'],
        ]);
        $courses = Course::query()->with(['owner:id,name', 'currentVersion'])
            ->where('status', CourseStatus::Published->value)
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%')))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filters['pricing'] ?? null, fn ($q, $p) => $q->where('pricing', $p))
            ->when($filters['language'] ?? null, fn ($q, $l) => $q->where('original_locale', $l))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $rows = $courses->getCollection()->map(fn (Course $c) => $this->presenter->catalogue($c));
        if ($this->wantsJson($request)) {
            return response()->json(['data' => $rows, 'meta' => ['current_page' => $courses->currentPage(), 'last_page' => $courses->lastPage(), 'total' => $courses->total()]]);
        }

        return view('learning.catalogue.index', ['courses' => $rows, 'paginator' => $courses, 'filters' => $filters]);
    }

    public function show(Request $request, Course $course, ProgressService $progress): JsonResponse|View
    {
        abort_unless($this->access->canViewOutline($request->user(), $course), 404);
        $user = $request->user();
        $enrolment = $this->access->activeEnrolment($user, $course);
        $payload = $this->presenter->catalogue($course) + [
            'viewer' => [
                'enrolled' => $enrolment !== null,
                'progress' => $enrolment ? $progress->summary($enrolment) : null,
                'is_owner' => $course->isOwnedBy($user),
                'has_access' => $this->access->hasPurchaseAccess($user, $course),
                'newer_version_available' => $enrolment !== null && $enrolment->course_version_id !== $course->current_version_id,
            ],
        ];

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $payload]);
        }

        $quote = $course->isFree() ? null : $this->checkout->quote($user, $course, $request->query('coupon'));

        return view('learning.catalogue.show', ['course' => $course, 'data' => $payload, 'quote' => $quote]);
    }

    public function quote(Request $request, Course $course): JsonResponse
    {
        abort_unless($this->access->canViewOutline($request->user(), $course), 404);
        $coupon = $request->validate(['coupon' => ['nullable', 'string', 'max:64']])['coupon'] ?? null;

        return response()->json(['data' => $this->checkout->quote($request->user(), $course, $coupon)->toArray()]);
    }

    public function enrol(Request $request, Course $course, ProgressService $progress): JsonResponse|RedirectResponse
    {
        abort_unless($this->access->canViewOutline($request->user(), $course), 404);
        $enrolment = $progress->enrol($request->user(), $course);

        return $this->wantsJson($request)
            ? response()->json(['data' => ['enrolment_id' => $enrolment->id, 'progress' => $progress->summary($enrolment)]], 201)
            : redirect()->route('learning.learn', $course)->with('status', 'You are enrolled.');
    }

    public function checkoutCourse(Request $request, Course $course): JsonResponse|RedirectResponse
    {
        abort_unless($course->status === CourseStatus::Published, 404);

        return $this->startCheckout($request, $course, $course->isFree());
    }

    public function checkoutBundle(Request $request, Bundle $bundle): JsonResponse|RedirectResponse
    {
        abort_unless($bundle->status === 'published', 404);

        return $this->startCheckout($request, $bundle, false);
    }

    public function showBundle(Request $request, Bundle $bundle): JsonResponse
    {
        abort_unless($bundle->status === 'published', 404);

        return response()->json(['data' => [
            'id' => $bundle->id,
            'title' => $bundle->title,
            'description' => $bundle->description,
            'price_minor' => $bundle->price_minor,
            'currency' => $bundle->currency,
            'tutor' => $bundle->owner?->name,
            'courses' => $bundle->courses()->where('status', CourseStatus::Published->value)->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'price_minor' => $c->priceMinor()]),
        ]]);
    }

    private function startCheckout(Request $request, Sellable $item, bool $isFree): JsonResponse|RedirectResponse
    {
        if ($isFree) {
            return $this->wantsJson($request)
                ? $this->error('free', 'This course is free: enrol instead.', 422)
                : back()->withErrors(['checkout' => 'This course is free: enrol instead.']);
        }
        $data = $request->validate(['coupon' => ['nullable', 'string', 'max:64']]);
        $key = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        if ($key === '' || strlen($key) > 100) {
            return $this->wantsJson($request)
                ? $this->error('idempotency_key_required', 'Send an Idempotency-Key header (up to 100 characters).', 400)
                : back()->withErrors(['checkout' => 'The checkout form expired. Reload the page and try again.']);
        }
        $decision = $this->safeguarding->allows($request->user(), 'pay', $item instanceof \Illuminate\Database\Eloquent\Model ? $item : null);
        if (! $decision->allowed) {
            return $this->wantsJson($request)
                ? $this->error('not_allowed', $decision->reason ?? 'Purchases are not allowed for this account.', 403)
                : back()->withErrors(['checkout' => $decision->reason ?? 'Purchases are not allowed for this account.']);
        }

        $result = $this->checkout->start($request->user(), $item, $data['coupon'] ?? null, $key);
        if ($result->status === 'unavailable') {
            return $this->wantsJson($request)
                ? $this->error('payments_unavailable', $result->message, 503)
                : back()->withErrors(['checkout' => $result->message]);
        }
        if ($this->wantsJson($request)) {
            return response()->json(['data' => ['status' => $result->status, 'message' => $result->message, 'redirect_url' => $result->redirectUrl, 'order_reference' => $result->orderReference]], 202);
        }

        return $result->redirectUrl ? redirect()->away($result->redirectUrl) : back()->with('status', $result->message);
    }
}
