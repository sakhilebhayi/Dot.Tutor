<?php

namespace App\Domain\Comms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email copy of an in-app notification: the same title and words, one link, why it came and where to change
 * that, and a calendar file for lesson confirmations and reminders. Sent by the delivery worker, which itself runs
 * on the queue (cron), so a person's request never waits on mail; the delivery row says "sent" only once the
 * mailer accepted it.
 */
class CommsMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $heading,
        public string $body,
        public ?string $actionUrl,
        public ?string $actionLabel,
        public string $categoryLabel,
        public bool $mandatory,
        public string $settingsUrl,
        public ?string $ics = null,
        public ?string $icsName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'comms::mail.notification', text: 'comms::mail.notification-text');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->ics === null) {
            return [];
        }

        return [Attachment::fromData(fn () => $this->ics, $this->icsName ?? 'lesson.ics')->withMime('text/calendar; charset=utf-8; method=PUBLISH')];
    }
}
