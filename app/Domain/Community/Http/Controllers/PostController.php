<?php

namespace App\Domain\Community\Http\Controllers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\Language;
use App\Domain\Community\Http\Concerns\AnswersBoth;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Posts;
use App\Domain\Community\Services\RoomAccess;
use App\Domain\Community\Services\Rooms;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Safety\Enums\ReportCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PostController extends Controller
{
    use AnswersBoth;

    public function __construct(
        private readonly Posts $posts,
        private readonly RoomAccess $access,
        private readonly Rooms $rooms,
    ) {}

    /** The ask form (also used for discussions, explanations, revision techniques and material). */
    public function create(Request $request, Room $room, LanguageProfile $languages): View
    {
        $user = $request->user();
        if (! $this->access->canRead($user, $room)) {
            $this->rooms->authorizeEntry($user, $room);
            abort(404);
        }
        $room->loadMissing(['subject', 'level.stage', 'curriculum']);
        $kind = in_array($request->query('kind'), Post::THREAD_KINDS, true) ? $request->query('kind') : Post::QUESTION;

        return view('community.ask', [
            'room' => $room,
            'kind' => $kind,
            'canParticipate' => $this->access->canParticipate($user, $room),
            'languages' => Language::query()->where('is_active', true)->where('modality', '!=', 'signed')->orderBy('sort')->get(),
            'defaultLocale' => $languages->interfaceLocale($user) ?: app()->getLocale(),
            'myResources' => LibraryResource::query()->where('owner_id', $user->id)->where('status', 'published')->orderByDesc('id')->limit(30)->get(),
            'isMinorRoom' => $room->isForMinors(),
            'band' => $this->access->band($user),
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    public function store(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Post::THREAD_KINDS)],
            'title' => ['required', 'string', 'min:5', 'max:'.config('community.max_title')],
            'body' => ['required', 'string', 'min:2', 'max:'.config('community.max_body')],
            'original_locale' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'library_resource_id' => ['nullable', 'required_if:kind,material', 'integer'],
        ]);

        $post = $this->posts->create($request->user(), $room, $data['kind'], $data);

        if ($this->wantsJson($request)) {
            return response()->json(['post' => $this->postJson($post)], 201);
        }

        return redirect()->route('rooms.posts.show', $post)->with(
            'flash.success',
            $post->status === Post::HELD ? 'Saved. A moderator checks it before others can see it.' : Post::kindLabel($post->kind).' posted.',
        );
    }

    public function show(Request $request, Post $post, LanguageProfile $languages): View|JsonResponse
    {
        $user = $request->user();
        if ($post->parent_id !== null) {
            return $this->show($request, $post->parent, $languages);
        }
        abort_unless($this->posts->canSee($user, $post), 404);

        $post->load(['room.subject', 'room.level.stage', 'room.curriculum', 'author']);
        $room = $post->room;
        $children = Post::query()->with('author')->where('parent_id', $post->id)->visibleTo($user)
            ->orderByRaw('case when id = ? then 0 else 1 end', [(int) $post->accepted_answer_id])->orderBy('id')->get();

        $mayResolve = $post->kind === Post::QUESTION && ($post->author_id === $user->id || $this->access->isTutorIn($user, $room));

        if ($this->wantsJson($request)) {
            return response()->json([
                'post' => $this->postJson($post),
                'room' => $this->roomJson($room),
                'replies' => $children->map(fn (Post $c) => $this->postJson($c) + ['accepted' => $c->id === $post->accepted_answer_id])->all(),
                'can_resolve' => $mayResolve,
            ]);
        }

        $resource = $post->library_resource_id ? LibraryResource::query()->find($post->library_resource_id) : null;
        $interface = $languages->interfaceLocale($user) ?: app()->getLocale();

        return view('community.post', [
            'post' => $post,
            'room' => $room,
            'children' => $children,
            'resource' => $resource,
            'canParticipate' => $this->access->canParticipate($user, $room),
            'mayResolve' => $mayResolve,
            'isOperator' => $this->access->isOperator($user),
            'categories' => ReportCategory::cases(),
            'translateTo' => $interface,
            'translationAvailable' => Route::has('ai.translations.store') && config('ai.enabled') && filled(config('ai.anthropic.api_key')),
            'languageNames' => Language::query()->pluck('name_native', 'code'),
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    /** An answer under a question, or a reply under a discussion, explanation, technique or material. */
    public function reply(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        abort_unless($post->isThread(), 404);
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:'.config('community.max_body')],
            'original_locale' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);

        $kind = $post->kind === Post::QUESTION ? Post::ANSWER : Post::REPLY;
        $reply = $this->posts->create($request->user(), $post->room, $kind, $data, $post);

        if ($this->wantsJson($request)) {
            return response()->json(['post' => $this->postJson($reply)], 201);
        }

        return redirect()->to(route('rooms.posts.show', $post).'#post-'.$reply->id)->with(
            'flash.success',
            $reply->status === Post::HELD ? 'Saved. A moderator checks it before others can see it.' : ($kind === Post::ANSWER ? 'Answer posted.' : 'Reply posted.'),
        );
    }

    public function edit(Request $request, Post $post): View
    {
        abort_unless($post->author_id === $request->user()->id && in_array($post->status, [Post::VISIBLE, Post::HELD], true), 404);
        $post->load('room.subject', 'room.level', 'room.curriculum');

        return view('community.edit', ['post' => $post, 'room' => $post->room, 'step' => $this->access->band($request->user()) === 'child' ? 'young' : 'standard']);
    }

    public function update(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $data = $request->validate([
            'title' => [$post->isThread() ? 'required' : 'nullable', 'string', 'min:5', 'max:'.config('community.max_title')],
            'body' => ['required', 'string', 'min:2', 'max:'.config('community.max_body')],
        ]);

        $post = $this->posts->edit($request->user(), $post, $data);

        return $this->wantsJson($request)
            ? response()->json(['post' => $this->postJson($post)])
            : redirect()->to(route('rooms.posts.show', $post->isThread() ? $post : $post->parent_id).($post->isThread() ? '' : '#post-'.$post->id))
                ->with('flash.success', 'Your changes are saved. The earlier version is kept in the history.');
    }

    /** Edit history: earlier versions of a post, newest first. */
    public function history(Request $request, Post $post): View|JsonResponse
    {
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $revisions = $post->revisions()->get();

        if ($this->wantsJson($request)) {
            return response()->json(['revisions' => $revisions->map(fn ($r) => ['title' => $r->title, 'body' => $r->body, 'replaced_at' => $r->created_at?->toIso8601String()])->all()]);
        }

        return view('community.history', ['post' => $post->load('room.subject', 'room.level', 'room.curriculum'), 'revisions' => $revisions, 'step' => 'standard']);
    }

    public function destroy(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $room = $post->room;
        $this->posts->remove($request->user(), $post);

        return $this->wantsJson($request)
            ? response()->json(['removed' => true])
            : ($post->isThread()
                ? redirect()->route('rooms.show', $room)->with('flash.success', 'Removed.')
                : redirect()->route('rooms.posts.show', $post->parent_id)->with('flash.success', 'Removed.'));
    }

    /** Accept an answer (route post = the answer). */
    public function accept(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $question = $this->posts->accept($request->user(), $post);

        return $this->wantsJson($request)
            ? response()->json(['question' => $this->postJson($question)])
            : redirect()->to(route('rooms.posts.show', $question).'#post-'.$post->id)->with('flash.success', 'Answer accepted. The question is resolved.');
    }

    public function resolve(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        abort_unless($this->posts->canSee($request->user(), $post), 404);
        $resolved = $request->boolean('resolved', true);
        $question = $this->posts->resolve($request->user(), $post, $resolved);

        return $this->wantsJson($request)
            ? response()->json(['question' => $this->postJson($question)])
            : redirect()->route('rooms.posts.show', $question)->with('flash.success', $resolved ? 'Marked as resolved.' : 'Opened again.');
    }

    public function report(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->posts->report($request->user(), $post, ReportCategory::from($data['category']), $data['details'] ?? null);
        $message = 'Thank you. A moderator will look at it.';

        return $this->wantsJson($request)
            ? response()->json(['reported' => true, 'message' => $message], 201)
            : back(303)->with('flash.success', $message);
    }
}
