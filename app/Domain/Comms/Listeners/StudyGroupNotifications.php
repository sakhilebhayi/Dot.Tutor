<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Events\StudyGroupUpdated;
use App\Domain\Comms\Messenger;
use App\Models\User;

/** One notification per study-group member; unread updates about one group collapse into the latest. */
final class StudyGroupNotifications
{
    public function __construct(private readonly Messenger $m) {}

    public function handle(StudyGroupUpdated $event): void
    {
        $occurrence = $event->occurrenceKey ?? sha1($event->kind.'|'.$event->summary);
        $title = match ($event->kind) {
            'session_scheduled' => 'New session in '.$event->groupName,
            'session_changed' => 'Session changed in '.$event->groupName,
            'answer' => 'New answer in '.$event->groupName,
            default => 'Update in '.$event->groupName,
        };

        User::query()->whereIn('id', array_values(array_unique(array_map('intval', $event->memberIds))))
            ->when($event->actorId !== null, fn ($q) => $q->whereKeyNot($event->actorId))
            ->orderBy('id')->each(function (User $member) use ($event, $occurrence, $title) {
                $this->m->send(new CommsMessage($member, 'study_group_update', 'group:'.$event->groupId.':'.$occurrence, $title,
                    mb_substr($event->summary, 0, 300), $event->url, 'group-'.$event->groupId, $event->actorId, replaceUnread: true,
                    data: ['group_id' => $event->groupId]));
            });
    }
}
