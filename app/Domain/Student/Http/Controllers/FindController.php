<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Academic\Models\AcademicLevel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The find form submits here first: the level's curriculum is filled in from the level (so the curriculum term is
 * never checked against a curriculum the learner did not pick), empty fields are dropped, and the learner lands on
 * the marketplace's own /find-a-tutor with a clean query. Matching itself is the marketplace's (D-1107).
 */
class FindController extends Controller
{
    private const KEYS = ['subject_id', 'level_id', 'languages', 'bilingual_ok', 'format', 'area', 'budget_rand', 'help_type', 'weekdays', 'from_time', 'to_time', 'need'];

    public function search(Request $request): RedirectResponse
    {
        $query = collect($request->only(self::KEYS))
            ->map(fn ($v) => is_array($v) ? array_values(array_filter($v, fn ($x) => $x !== null && $x !== '')) : $v)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->all();

        if (($query['format'] ?? null) === 'either') {
            unset($query['format']);
        }
        if (isset($query['level_id']) && ($level = AcademicLevel::query()->find((int) $query['level_id']))) {
            $query['curriculum_id'] = $level->curriculum_id;
        } else {
            unset($query['level_id']);
        }

        return redirect()->route('marketplace.find', $query);
    }
}
