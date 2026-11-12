<?php

namespace App\Domain\Community\Services;

use App\Domain\Community\Models\GroupMember;
use App\Domain\Community\Models\GroupResource;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\SessionAttendance;
use App\Models\User;

/**
 * Meaningful learning indicators only (brief section 11): questions resolved, answers accepted, resources
 * contributed, study sessions attended, study group participation, and milestones reached from those. There are
 * no likes, follower counts, views or leaderboards, and nobody is ranked against anyone else.
 */
class Indicators
{
    /** Milestones: [indicator, threshold, wording]. */
    private const MILESTONES = [
        ['answers_accepted', 1, 'Your first answer was accepted'],
        ['answers_accepted', 10, 'Ten of your answers were accepted'],
        ['questions_resolved', 1, 'Your first question was resolved'],
        ['questions_resolved', 10, 'Ten of your questions were resolved'],
        ['resources_contributed', 1, 'You shared your first study material'],
        ['resources_contributed', 5, 'You shared five study materials'],
        ['sessions_attended', 1, 'You attended your first study session'],
        ['sessions_attended', 10, 'You attended ten study sessions'],
    ];

    /** @return array{questions_resolved: int, answers_accepted: int, resources_contributed: int, sessions_attended: int, groups_active: int, milestones: list<array{text: string, reached: bool}>} */
    public function forUser(User $user): array
    {
        $answersAccepted = Post::query()->where('status', Post::VISIBLE)
            ->whereIn('id', Post::query()->whereNotNull('accepted_answer_id')->where('status', Post::VISIBLE)->select('accepted_answer_id'))
            ->where('author_id', $user->id)->count();

        $figures = [
            'questions_resolved' => Post::query()->where('author_id', $user->id)->where('kind', Post::QUESTION)
                ->where('status', Post::VISIBLE)->whereNotNull('resolved_at')->count(),
            'answers_accepted' => $answersAccepted,
            'resources_contributed' => Post::query()->where('author_id', $user->id)->where('kind', Post::MATERIAL)->where('status', Post::VISIBLE)->count()
                + GroupResource::query()->where('added_by_id', $user->id)->count(),
            'sessions_attended' => SessionAttendance::query()->where('user_id', $user->id)->count(),
            'groups_active' => GroupMember::query()->where('user_id', $user->id)->where('status', GroupMember::ACTIVE)->count(),
        ];

        $figures['milestones'] = array_map(fn (array $m) => ['text' => $m[2], 'reached' => $figures[$m[0]] >= $m[1]], self::MILESTONES);

        return $figures;
    }
}
