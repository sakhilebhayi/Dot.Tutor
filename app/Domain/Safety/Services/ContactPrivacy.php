<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Models\User;

/**
 * For profile text (a tutor's bio, a learner's about-me) shown to someone else: when the gate's view_contact
 * check fails, contact details are hidden and the note says why. Marketplace and community views call this.
 */
class ContactPrivacy
{
    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly ContactRedactor $redactor,
    ) {}

    /** @return array{text: string, redacted: bool, note: ?string} */
    public function profileText(?User $viewer, User $owner, string $text): array
    {
        if ($viewer !== null && $this->gate->allows($viewer, 'view_contact', $owner)->allowed) {
            return ['text' => $text, 'redacted' => false, 'note' => null];
        }

        $result = $this->redactor->redact($text);
        $redacted = $result['redactions'] !== [];

        return ['text' => $result['text'], 'redacted' => $redacted, 'note' => $redacted ? ContactRedactor::NOTE : null];
    }
}
