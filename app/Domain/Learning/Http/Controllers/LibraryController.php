<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Enums\QualityTier;
use App\Domain\Learning\Enums\ResourceLicence;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Enums\ResourceType;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceRecommendation;
use App\Domain\Learning\Models\ResourceReport;
use App\Domain\Learning\Services\LibraryService;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** The resource library: search and filters, upload, download, recommend, report, withdraw, buy. */
class LibraryController extends LearningController
{
    public function __construct(private LibraryService $library, private AcademicScopeLookup $academic) {}

    public function index(Request $request): JsonResponse|View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ResourceType::values())],
            'country_code' => ['nullable', 'string', 'size:2'],
            'curriculum_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'subject_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
            'difficulty' => ['nullable', Rule::in(['introductory', 'standard', 'advanced'])],
            'institution' => ['nullable', 'string', 'max:160'],
            'licence' => ['nullable', Rule::in(ResourceLicence::values())],
            'tier' => ['nullable', Rule::in(QualityTier::values())],
            'free' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $query = LibraryResource::query()->with('owner:id,name')->withCount('recommendations');
        if (! empty($filters['mine'])) {
            $query->where('owner_id', $user->id);
        } else {
            $query->where('status', ResourceStatus::Published->value);
        }
        $query
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%')->orWhere('topic', 'like', '%'.$term.'%')))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['country_code'] ?? null, fn ($q, $v) => $q->where('country_code', strtoupper($v)))
            ->when($filters['curriculum_id'] ?? null, fn ($q, $v) => $q->where('curriculum_id', $v))
            ->when($filters['level_id'] ?? null, fn ($q, $v) => $q->where('level_id', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['topic'] ?? null, fn ($q, $v) => $q->where('topic', 'like', '%'.$v.'%'))
            ->when($filters['language'] ?? null, fn ($q, $v) => $q->where('language', $v))
            ->when($filters['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($filters['institution'] ?? null, fn ($q, $v) => $q->where('institution', 'like', '%'.$v.'%'))
            ->when($filters['licence'] ?? null, fn ($q, $v) => $q->where('licence', $v))
            ->when($filters['tier'] ?? null, fn ($q, $v) => $q->where('quality_tier', $v))
            ->when(array_key_exists('free', $filters) && $filters['free'] !== null, fn ($q) => $q->where('is_free', (bool) $filters['free']));
        $page = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $rows = $page->getCollection()->map(fn (LibraryResource $r) => $this->present($r));

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
        }

        return view('learning.library.index', [
            'resources' => $rows,
            'paginator' => $page,
            'filters' => $filters,
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, LibraryResource $resource): JsonResponse|View
    {
        abort_unless($this->library->canSee($request->user(), $resource), 404);
        $payload = $this->present($resource) + [
            'reviews' => $resource->reviews->map(fn ($r) => ['reviewer' => $r->reviewer_name, 'outcome' => $r->outcome, 'notes' => $r->notes, 'at' => $r->created_at->toIso8601String()])->all(),
            'can_download' => $this->library->canDownload($request->user(), $resource),
            'recommended_by_you' => ResourceRecommendation::query()->where('resource_id', $resource->id)->where('user_id', $request->user()->id)->exists(),
            'is_owner' => $resource->isOwnedBy($request->user()),
            'reports' => $resource->isOwnedBy($request->user()) || $request->user()->is_platform_operator
                ? $resource->reports()->latest('id')->get(['id', 'kind', 'status', 'details', 'counter_notice', 'decision_notes', 'created_at'])->toArray()
                : [],
        ];

        return $this->wantsJson($request) ? response()->json(['data' => $payload]) : view('learning.library.show', ['resource' => $resource, 'data' => $payload]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(ResourceType::values())],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'curriculum_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'subject_id' => ['nullable', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'difficulty' => ['nullable', Rule::in(['introductory', 'standard', 'advanced'])],
            'institution' => ['nullable', 'string', 'max:160'],
            'ownership' => ['required', Rule::in(['own_work', 'third_party'])],
            'licence' => ['required', Rule::in(ResourceLicence::values())],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'is_free' => ['nullable', 'boolean'],
            'price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rights_confirmed' => ['accepted'],
            'file' => ['required', 'file'],
        ], ['rights_confirmed.accepted' => 'Confirm that you have the right to share this file under the licence you chose.']);
        $data['is_free'] = $request->has('is_free') ? $request->boolean('is_free') : true;

        $resource = $this->library->upload($request->user(), $data, $request->file('file'));
        $message = $resource->status === ResourceStatus::PendingReview
            ? 'Uploaded. A reviewer will confirm the official source before it is visible to others.'
            : 'Uploaded and published.';

        return $this->wantsJson($request)
            ? response()->json(['data' => $this->present($resource), 'message' => $message], 201)
            : redirect()->route('learning.library.show', $resource)->with('status', $message);
    }

    public function download(Request $request, LibraryResource $resource): BinaryFileResponse|JsonResponse
    {
        abort_unless($this->library->canSee($request->user(), $resource), 404);
        if (! $this->library->canDownload($request->user(), $resource)) {
            return $this->error('forbidden', 'Buy this resource to download it.', 403);
        }
        $disk = Storage::disk('learning');
        abort_unless($disk->exists($resource->path), 404);
        $name = (Str::slug($resource->title) ?: 'resource').'.'.pathinfo($resource->path, PATHINFO_EXTENSION);
        $response = new BinaryFileResponse($disk->path($resource->path), 200, [
            'Content-Type' => $resource->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, Str::ascii($name));

        return $response;
    }

    public function recommend(Request $request, LibraryResource $resource): JsonResponse|RedirectResponse
    {
        abort_unless($resource->status === ResourceStatus::Published, 404);
        if ($resource->isOwnedBy($request->user())) {
            return $this->wantsJson($request) ? $this->error('own_resource', 'You cannot recommend your own resource.', 422) : back()->withErrors(['recommend' => 'You cannot recommend your own resource.']);
        }
        if ($request->isMethod('delete')) {
            ResourceRecommendation::query()->where('resource_id', $resource->id)->where('user_id', $request->user()->id)->delete();
        } else {
            ResourceRecommendation::query()->firstOrCreate(['resource_id' => $resource->id, 'user_id' => $request->user()->id]);
        }
        $count = $resource->recommendations()->count();

        return $this->wantsJson($request) ? response()->json(['data' => ['recommendations' => $count]]) : back();
    }

    public function report(Request $request, LibraryResource $resource): JsonResponse|RedirectResponse
    {
        abort_unless($this->library->canSee($request->user(), $resource), 404);
        $copyright = $request->input('kind') === 'copyright';
        $data = $request->validate([
            'kind' => ['required', Rule::in(['copyright', 'incorrect', 'improvement', 'other'])],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'claimant_name' => [$copyright ? 'required' : 'nullable', 'string', 'max:160'],
            'work_reference' => [$copyright ? 'required' : 'nullable', 'string', 'max:2048'],
            'good_faith' => [$copyright ? 'accepted' : 'nullable'],
        ], ['good_faith.accepted' => 'A copyright notice must state, in good faith, that the use is not authorised by the rights holder.']);
        if ($resource->isOwnedBy($request->user()) && $copyright) {
            return $this->wantsJson($request) ? $this->error('own_resource', 'Withdraw your own resource instead.', 422) : back()->withErrors(['kind' => 'Withdraw your own resource instead.']);
        }
        $report = $this->library->report($request->user(), $resource, $data);

        return $this->wantsJson($request)
            ? response()->json(['data' => ['id' => $report->id, 'status' => $report->status, 'resource_status' => $resource->fresh()->status->value]], 201)
            : back()->with('status', 'Thank you. The report is with the moderators.');
    }

    public function counterNotice(Request $request, ResourceReport $report): JsonResponse|RedirectResponse
    {
        $statement = $request->validate(['statement' => ['required', 'string', 'min:20', 'max:5000']])['statement'];
        $this->library->counterNotice($request->user(), $report, $statement);

        return $this->wantsJson($request) ? response()->json(['data' => ['status' => 'countered']]) : back()->with('status', 'Counter-notice sent to the moderators.');
    }

    public function withdraw(Request $request, LibraryResource $resource): JsonResponse|RedirectResponse
    {
        Gate::authorize('withdraw', $resource);
        $resource->forceFill(['status' => ResourceStatus::Withdrawn])->save();

        return $this->wantsJson($request) ? response()->json(['data' => ['status' => 'withdrawn']]) : redirect()->route('learning.library.index')->with('status', 'Resource withdrawn.');
    }

    public function checkout(Request $request, LibraryResource $resource, CheckoutGateway $checkout): JsonResponse|RedirectResponse
    {
        abort_unless($resource->status === ResourceStatus::Published, 404);
        if ($resource->is_free) {
            return $this->error('free', 'This resource is free to download.', 422);
        }
        $key = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        if ($key === '') {
            return $this->error('idempotency_key_required', 'Send an Idempotency-Key header.', 400);
        }
        $result = $checkout->start($request->user(), $resource, $request->input('coupon'), $key);
        if ($result->status === 'unavailable') {
            return $this->wantsJson($request) ? $this->error('payments_unavailable', $result->message, 503) : back()->withErrors(['checkout' => $result->message]);
        }

        return response()->json(['data' => ['status' => $result->status, 'message' => $result->message, 'redirect_url' => $result->redirectUrl]], 202);
    }

    /** @return array<string, mixed> */
    private function present(LibraryResource $resource): array
    {
        $latestReview = $resource->quality_tier === QualityTier::Reviewed || $resource->quality_tier === QualityTier::OfficiallySourced
            ? $resource->reviews()->whereIn('outcome', ['approved', 'source_confirmed'])->first()
            : null;

        return [
            'id' => $resource->id,
            'title' => $resource->title,
            'description' => $resource->description,
            'type' => $resource->type->value,
            'type_label' => $resource->type->label(),
            'country_code' => $resource->country_code,
            'academic' => $this->academic->describe($resource->curriculum_id, $resource->level_id, $resource->subject_id, $resource->topic_id),
            'topic' => $resource->topic,
            'language' => $resource->language,
            'difficulty' => $resource->difficulty,
            'institution' => $resource->institution,
            'contributor' => $resource->owner?->name ?? 'Deleted account',
            'ownership' => $resource->ownership,
            'licence' => $resource->licence->value,
            'licence_label' => $resource->licence->label(),
            'source_url' => $resource->source_url,
            'is_free' => $resource->is_free,
            'price_minor' => $resource->priceMinor(),
            'currency' => $resource->currency,
            'status' => $resource->status->value,
            'quality_tier' => $resource->quality_tier->value,
            'quality_label' => $resource->quality_tier->label(),
            'reviewed_by' => $latestReview ? ['name' => $latestReview->reviewer_name, 'at' => $latestReview->created_at->toDateString()] : null,
            'mime' => $resource->mime,
            'size' => $resource->size,
            'recommendations' => $resource->recommendations_count ?? $resource->recommendations()->count(),
            'created_at' => $resource->created_at->toIso8601String(),
        ];
    }
}
