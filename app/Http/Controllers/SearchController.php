<?php

namespace App\Http\Controllers;

use App\Support\Search\TutorSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The header search, two plain GET endpoints (so it works with no script and a result page can be linked or reloaded):
 *
 *   /search           the full results page, a longer list per group
 *   /search/suggest   the same search as JSON groups for the dropdown (js/ds/header-search.js)
 *
 * Both are behind the signed-in group, and both read the scope from the request only as a REQUEST to be checked: TutorSearch::resolveScope()
 * falls back to `learn` unless the person really is a tutor, a linked guardian or an operator.
 */
class SearchController extends Controller
{
    public function index(Request $request, TutorSearch $search): View
    {
        [$q, $scope] = $this->input($request, $search);
        $user = $request->user();
        $typed = TutorSearch::isSearchable($q);

        return view('search.index', [
            'q' => $q,
            'scope' => $scope,
            'scopes' => collect($search->scopesFor($user))->mapWithKeys(fn (string $s) => [$s => TutorSearch::SCOPES[$s]])->all(),
            'typed' => $typed,
            'words' => $typed ? TutorSearch::words($q) : [],
            'groups' => $typed ? $search->search($user, $scope, $q, 25) : [],
            'pages' => $typed ? [] : $search->pages($user, $scope, null, 12),
        ]);
    }

    public function suggest(Request $request, TutorSearch $search): JsonResponse
    {
        [$q, $scope] = $this->input($request, $search);

        $groups = [];
        foreach ($search->search($request->user(), $scope, $q, 4) as $label => $hits) {
            $groups[] = ['head' => $label, 'rows' => array_map(fn ($hit) => $hit->toRow(), $hits)];
        }

        return response()->json(['groups' => $groups], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array{0: string, 1: string} the trimmed text and the scope this person may use */
    private function input(Request $request, TutorSearch $search): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:'.(TutorSearch::MAX_LENGTH * 2)],
            'scope' => ['nullable', 'string', 'max:20'],
        ]);

        return [
            trim(mb_substr((string) ($data['q'] ?? ''), 0, TutorSearch::MAX_LENGTH)),
            $search->resolveScope($request->user(), $data['scope'] ?? null),
        ];
    }
}
