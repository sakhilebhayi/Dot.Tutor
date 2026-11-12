<?php

namespace App\Domain\Comms;

use App\Models\User;

/**
 * One notification for one person, in plain language, before it is written anywhere.
 *
 * eventKey identifies the occurrence ("booking-event:12:tutor"): the same key for the same person is written
 * once, however often the event arrives. collapseKey groups updates about one subject (one booking), so the unread
 * badge counts it once, a phone shows the latest, and an older queued push that a newer one overtook is not sent.
 * aboutMinor marks a message whose subject involves a minor: it is never mirrored outside Dot.Tutor (Dot.Notify).
 * data holds ids only (and `ics_session_id` to attach a calendar file to the email, `expires_at` after which a copy
 * is pointless).
 */
final readonly class CommsMessage
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public User $recipient,
        public string $type,
        public string $eventKey,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $collapseKey = null,
        public ?int $actorId = null,
        public bool $replaceUnread = false,
        public array $data = [],
        public bool $aboutMinor = false,
    ) {}

    public function actorIsRecipient(): bool
    {
        return $this->actorId !== null && $this->actorId === $this->recipient->id;
    }
}
