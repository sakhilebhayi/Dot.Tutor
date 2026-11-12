<?php

namespace App\Domain\Community\Http\Controllers;

use App\Domain\Community\Http\Concerns\AnswersBoth;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Services\Posts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Held room posts (children's pre-moderated posts, posts held after reports) for operators. Reports themselves are
 * worked in safety's queue, which can also hide and restore posts (Post is Moderatable).
 */
class ModerationController extends Controller
{
    use AnswersBoth;

    public function index(Request $request): View|JsonResponse
    {
        $held = Post::query()->with(['room.subject', 'room.level', 'room.curriculum', 'author'])
            ->where('status', Post::HELD)->orderBy('updated_at')->orderBy('id')->paginate(25);

        if ($this->wantsJson($request)) {
            return response()->json(['held' => collect($held->items())->map(fn (Post $p) => $this->postJson($p) + [
                'room' => $p->room?->displayName(),
                'held_reason' => $p->held_reason,
                'reporters' => $p->reporters_count,
                'contact_details_hidden' => $p->redactions,
            ])->all()]);
        }

        return view('community.moderation', ['held' => $held]);
    }

    public function decide(Request $request, Post $post, Posts $posts): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['release', 'remove'])], 'note' => ['nullable', 'string', 'max:500']]);
        $post = $posts->moderate($request->user(), $post, $data['decision'], $data['note'] ?? null);

        return $this->wantsJson($request)
            ? response()->json(['post' => $this->postJson($post)])
            : back(303)->with('flash.success', $data['decision'] === 'release' ? 'Released: everyone in the room can see it now.' : 'Removed from the room.');
    }
}
