<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Learning\Contracts\ProgressFeed;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Progress in plain words (D-1112): the learning domain's insights first, then each topic with a word for where the
 * learner stands and the evidence behind it, then what they did recently. No scores out of context, no leaderboards.
 */
class ProgressController extends Controller
{
    public function __invoke(Request $request, ProgressFeed $feed): View
    {
        $user = $request->user();
        $mastery = $feed->mastery($user);
        $subjects = Subject::query()->whereIn('id', array_filter(array_column($mastery, 'subject_id')) ?: [0])->pluck('name', 'id');

        return view('student.progress', [
            'insights' => $feed->insights($user),
            'bySubject' => collect($mastery)->groupBy(fn ($row) => $row['subject_id'] ? ($subjects[$row['subject_id']] ?? 'Other') : 'Other')->sortKeys(),
            'recent' => $feed->recentEvents($user, 15),
        ]);
    }
}
