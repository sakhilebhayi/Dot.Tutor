<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Contracts\ProgressFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The learner's own progress: topic mastery, plain-language insights and recent activity. */
class ProgressController extends LearningController
{
    public function __invoke(Request $request, ProgressFeed $feed): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'mastery' => $feed->mastery($user),
            'insights' => $feed->insights($user),
            'recent' => $feed->recentEvents($user, 20),
        ]]);
    }
}
