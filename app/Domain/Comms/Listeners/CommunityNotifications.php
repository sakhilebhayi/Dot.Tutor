<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Domain\Community\Events\AnswerPosted;
use App\Domain\Community\Events\JoinRequested;
use App\Domain\Community\Events\QuestionResolved;
use App\Domain\Community\Models\GroupMember;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Support\Names;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rooms and Q&A: an answer to your question, your answer accepted, your question resolved by a tutor, and a request
 * to join the tutor room or study group you run. Listens to the community domain's own events (dispatched after
 * commit). Only posts others can see are announced; people are named as the rooms name them (first name and last
 * initial), a minor by first name only; nothing about a minor leaves Dot.Tutor. No guardian copies (community is
 * not lessons, purchases or safety, D-1005).
 */
final class CommunityNotifications
{
    public function __construct(private readonly Messenger $m) {}

    public function answerPosted(AnswerPosted $event): void
    {
        try {
            $answer = Post::query()->with(['parent', 'room', 'author'])->find($event->answerId);
            $question = $answer?->parent;
            $asker = $question ? User::find($question->author_id) : null;

            if ($answer === null || $question === null || $asker === null || $answer->kind !== Post::ANSWER
                || ! $answer->isVisible() || ! $question->isVisible() || $asker->id === $answer->author_id) {
                return;
            }

            $this->m->send(new CommsMessage($asker, 'community_answer', 'post:'.$answer->id.':answered',
                'New answer to your question',
                $this->name($answer->author).' answered "'.$this->title($question).'".',
                route('rooms.posts.show', $question, false), 'post-'.$question->id, $answer->author_id, replaceUnread: true,
                data: ['post_id' => $question->id, 'answer_id' => $answer->id, 'action_label' => 'Read the answer'],
                aboutMinor: $this->involvesMinor($answer->room, $answer->author)));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify an answer.', ['post_id' => $event->answerId, 'error' => class_basename($e)]);
        }
    }

    public function questionResolved(QuestionResolved $event): void
    {
        try {
            $question = Post::query()->with('room')->find($event->questionId);
            $actor = User::find($event->actorId);

            if ($question === null || $question->kind !== Post::QUESTION || ! $question->isVisible() || $question->resolved_at === null) {
                return;
            }

            $url = route('rooms.posts.show', $question, false);
            $answer = $event->acceptedAnswerId ? Post::query()->find($event->acceptedAnswerId) : null;
            $answerer = $answer ? User::find($answer->author_id) : null;

            // The accepted answer's author (not when they accepted it themselves, or answered their own question).
            if ($answer !== null && $answerer !== null && $answer->isVisible() && $answerer->id !== $event->actorId && $question->accepted_answer_id === $answer->id) {
                $byAsker = $event->actorId === $question->author_id;
                $this->m->send(new CommsMessage($answerer, 'community_answer_accepted', 'post:'.$answer->id.':accepted',
                    'Your answer was accepted',
                    ($byAsker ? 'The person who asked' : 'A tutor in the room').' accepted your answer to "'.$this->title($question).'".',
                    $url, 'post-'.$question->id, $event->actorId,
                    data: ['post_id' => $question->id, 'answer_id' => $answer->id, 'action_label' => 'See the question'],
                    aboutMinor: $this->involvesMinor($question->room, $actor)));
            }

            // The asker, when someone else (a tutor in the room) resolved it.
            $asker = User::find($question->author_id);
            if ($asker !== null && $asker->id !== $event->actorId) {
                $this->m->send(new CommsMessage($asker, 'community_question_resolved', 'post:'.$question->id.':resolved:'.$question->resolved_at->timestamp,
                    'Your question was marked resolved',
                    $this->name($actor).', a tutor in the room, '.($answer !== null ? 'accepted an answer to' : 'marked resolved').' "'.$this->title($question).'". You can open it again if it is not.',
                    $url, 'post-'.$question->id, $event->actorId, replaceUnread: true,
                    data: ['post_id' => $question->id, 'action_label' => 'See the question'],
                    aboutMinor: $this->involvesMinor($question->room, $actor)));
            }
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a resolved question.', ['post_id' => $event->questionId, 'error' => class_basename($e)]);
        }
    }

    public function joinRequested(JoinRequested $event): void
    {
        try {
            $requester = User::find($event->requesterId);
            if ($requester === null) {
                return;
            }

            if ($event->scope === JoinRequested::GROUP) {
                $group = StudyGroup::query()->with('room')->find($event->targetId);
                $member = GroupMember::query()->find($event->membershipId);
                $owner = $group ? User::find($group->owner_id) : null;
                if ($group === null || $owner === null || $member?->status !== GroupMember::PENDING || $group->status !== 'active') {
                    return;
                }
                $waiting = GroupMember::query()->where('group_id', $group->id)->where('status', GroupMember::PENDING)->count();
                [$key, $collapse, $name, $url, $room] = ['group-member:'.$member->id.':requested', 'group-requests-'.$group->id, (string) $group->name, route('rooms.groups.show', $group, false), $group->room];
            } else {
                $room = Room::query()->find($event->targetId);
                $member = RoomMember::query()->find($event->membershipId);
                $owner = $room?->owner_id ? User::find($room->owner_id) : null;
                if ($room === null || $owner === null || $member?->status !== RoomMember::PENDING || ! $room->isActive()) {
                    return;
                }
                $waiting = RoomMember::query()->where('room_id', $room->id)->where('status', RoomMember::PENDING)->count();
                [$key, $collapse, $name, $url] = ['room-member:'.$member->id.':requested', 'room-requests-'.$room->id, $room->displayName(), route('rooms.show', $room, false)];
            }

            if ($owner->id === $requester->id) {
                return;
            }

            // One unread notice per room or group, replaced as more requests arrive.
            $this->m->send(new CommsMessage($owner, 'community_join_request', $key, 'Request to join '.$name,
                $this->name($requester).' asked to join. '.$waiting.' '.($waiting === 1 ? 'request is' : 'requests are').' waiting for your answer.',
                $url, $collapse, $requester->id, replaceUnread: true,
                data: [$event->scope.'_id' => $event->targetId, 'action_label' => 'Answer the request'],
                aboutMinor: $this->involvesMinor($room, $requester)));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a join request.', ['scope' => $event->scope, 'id' => $event->targetId, 'error' => class_basename($e)]);
        }
    }

    /** As the rooms show people (first name and last initial); a minor by first name only. */
    private function name(?User $user): string
    {
        if ($user === null) {
            return 'Someone';
        }

        return $this->m->isMinor($user) ? $this->m->firstName($user) : Names::short($user);
    }

    private function title(Post $question): string
    {
        return mb_strimwidth((string) ($question->title ?: 'your question'), 0, 90, '...');
    }

    private function involvesMinor(?Room $room, ?User $other): bool
    {
        return ($room?->isForMinors() ?? false) || ($other !== null && $this->m->isMinor($other));
    }
}
