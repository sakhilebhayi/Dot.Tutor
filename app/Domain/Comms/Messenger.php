<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Calendar\LessonCalendar;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Helpers the listeners share: send one message, send a learner's message with copies for their guardians when
 * the learner is a minor, name a learner safely for a tutor (a minor's first name only), and say a lesson's time in
 * the reader's own time zone.
 */
final class Messenger
{
    public function __construct(
        private readonly NotificationRouter $router,
        private readonly MinorRouting $minors,
        private readonly QuietHours $quiet,
    ) {}

    public function send(CommsMessage $message): ?string
    {
        return $this->router->send($message);
    }

    public function isMinor(User $user): bool
    {
        return $this->minors->isMinor($user);
    }

    /**
     * The learner's message (unless $toLearner is false), and for a minor one copy per linked guardian, written by
     * $guardianText($guardian) => [title, body]. Guardian copies link to the guardian dashboard unless $guardianUrl.
     *
     * @param  callable(User): array{0: string, 1: string}|null  $guardianText
     */
    public function learnerAndGuardians(User $learner, CommsMessage $message, ?callable $guardianText, bool $toLearner = true, ?string $guardianUrl = null): void
    {
        $minor = $this->minors->isMinor($learner);

        if ($toLearner) {
            $this->send($minor && ! $message->aboutMinor ? new CommsMessage(
                $message->recipient, $message->type, $message->eventKey, $message->title, $message->body, $message->url,
                $message->collapseKey, $message->actorId, $message->replaceUnread, $message->data, true,
            ) : $message);
        }

        if (! $minor || $guardianText === null) {
            return;
        }

        foreach ($this->minors->guardiansOf($learner) as $guardian) {
            [$title, $body] = $guardianText($guardian);
            $this->send(new CommsMessage(
                recipient: $guardian,
                type: $message->type,
                eventKey: $message->eventKey.':guardian',
                title: $title,
                body: $body,
                url: $guardianUrl ?? route('safety.guardian.dashboard', absolute: false),
                collapseKey: $message->collapseKey,
                actorId: $message->actorId,
                data: $message->data,
                aboutMinor: true,
            ));
        }
    }

    /** What a tutor (or anyone outside the family) is told a learner is called: a minor's first name only. */
    public function learnerName(?User $learner): string
    {
        if ($learner === null) {
            return 'A learner';
        }

        return $this->minors->isMinor($learner) ? LessonCalendar::firstName($learner->name) : (string) $learner->name;
    }

    public function firstName(?User $user): string
    {
        return LessonCalendar::firstName($user?->name);
    }

    /** "Thu 12 Nov at 15:00 SAST" in the reader's time zone. */
    public function when(CarbonInterface $at, User $reader): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->quiet->timezoneFor($reader));

        return $local->format('D j M').' at '.$local->format('H:i').' '.$local->format('T');
    }

    public function lessonWhat(TutorSession $session): string
    {
        $subject = (string) ($session->subject?->name ?? 'tutoring');

        return $session->isGroup() ? $subject.' group lesson' : $subject.' lesson';
    }
}
