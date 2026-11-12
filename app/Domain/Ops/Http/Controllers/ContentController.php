<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Services\Posts;
use App\Domain\Learning\Enums\QualityTier;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReport;
use App\Domain\Learning\Services\LibraryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Educational content (brief sections 9 and 20): held room posts (community), resources waiting for a source
 * check and quality reviews, copyright and content complaints with takedowns (learning library), the quality
 * tiers on record, and published courses. Decisions call the community Posts and learning LibraryService services.
 */
class ContentController extends OpsController
{
    public const TABS = ['posts' => 'Held room posts', 'resources' => 'Resources to review', 'complaints' => 'Complaints and takedowns', 'library' => 'Quality tiers', 'courses' => 'Courses'];

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? $request->query('tab') : 'posts';

        return view('ops.moderation', [
            'tab' => $tab,
            'counts' => [
                'posts' => Post::query()->where('status', Post::HELD)->count(),
                'resources' => LibraryResource::query()->where('status', ResourceStatus::PendingReview->value)->count(),
                'complaints' => ResourceReport::query()->whereIn('status', ['open', 'countered'])->count(),
            ],
            'posts' => $tab === 'posts' ? Post::query()->with(['room.subject', 'room.level', 'room.curriculum', 'author'])->where('status', Post::HELD)->orderBy('updated_at')->orderBy('id')->paginate(25)->withQueryString() : null,
            'resources' => $tab === 'resources' ? LibraryResource::query()->with(['owner', 'subject', 'reviews'])
                ->where(fn ($q) => $q->where('status', ResourceStatus::PendingReview->value)
                    ->orWhere(fn ($w) => $w->where('status', ResourceStatus::Published->value)->whereIn('quality_tier', [QualityTier::Community->value, QualityTier::TutorCreated->value])))
                ->orderByRaw("case when status = 'pending_review' then 0 else 1 end")->orderBy('id')->paginate(25)->withQueryString() : null,
            'complaints' => $tab === 'complaints' ? ResourceReport::query()->with('resource.owner')
                ->orderByRaw("case when status in ('open', 'countered') then 0 else 1 end")->orderByDesc('id')->paginate(25)->withQueryString() : null,
            'tiers' => $tab === 'library' ? LibraryResource::query()->where('status', ResourceStatus::Published->value)
                ->select('quality_tier', DB::raw('count(*) as n'))->groupBy('quality_tier')->pluck('n', 'quality_tier') : null,
            'statuses' => $tab === 'library' ? LibraryResource::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status') : null,
            'courses' => $tab === 'courses' ? DB::table('learning_courses')->leftJoin('users', 'users.id', '=', 'learning_courses.owner_id')
                ->where('learning_courses.status', 'published')->orderByDesc('learning_courses.published_at')
                ->select('learning_courses.id', 'learning_courses.title', 'learning_courses.published_at', 'learning_courses.pricing', 'learning_courses.price_minor', 'learning_courses.currency', 'users.name as owner')
                ->paginate(25)->withQueryString() : null,
        ]);
    }

    public function decidePost(Request $request, Post $post, Posts $posts): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['release', 'remove'])], 'note' => ['nullable', 'string', 'max:500']]);
        $posts->moderate($request->user(), $post, $data['decision'], $data['note'] ?? null);

        return $this->done($data['decision'] === 'release' ? 'Released: everyone in the room can see it now.' : 'Removed from the room.');
    }

    public function reviewResource(Request $request, LibraryResource $resource, LibraryService $library): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['approved', 'source_confirmed', 'changes_requested', 'rejected'])],
            'notes' => [in_array($request->input('outcome'), ['approved', 'source_confirmed'], true) ? 'nullable' : 'required', 'string', 'max:3000'],
        ]);

        $library->review($request->user(), $resource, $data['outcome'], $data['notes'] ?? null);

        return $this->done('Review recorded with your name. The resource now shows its tier: '.$resource->fresh()->quality_tier->label().'.');
    }

    public function decideComplaint(Request $request, ResourceReport $report, LibraryService $library): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['uphold', 'dismiss'])], 'notes' => ['nullable', 'string', 'max:3000']]);
        $library->decide($request->user(), $report, $data['decision'], $data['notes'] ?? null);

        return $this->done($data['decision'] === 'uphold' ? 'Upheld: the file stays down and the same file cannot be uploaded again.' : 'Dismissed: the resource is restored.');
    }
}
