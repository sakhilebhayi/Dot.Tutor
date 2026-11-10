<?php

namespace App\Domain\Classroom\Support;

use Carbon\CarbonInterface;
use JsonSerializable;

/**
 * Whether a person may be in a lesson's classroom right now, and why not. Codes: not_participant, not_confirmed,
 * lesson_over, too_early, closed, ended, removed, plus any safeguarding code (guardian_approval_missing,
 * guardian_consent_required, tutor_screening_missing, adult_screening_missing, account_suspended).
 */
final class Admission implements JsonSerializable
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $role,
        public readonly ?string $code,
        public readonly string $message,
        public readonly ?CarbonInterface $opensAt,
        public readonly ?CarbonInterface $closesAt,
    ) {}

    public static function allow(string $role, CarbonInterface $opensAt, CarbonInterface $closesAt): self
    {
        return new self(true, $role, null, 'You can join this lesson.', $opensAt, $closesAt);
    }

    public static function deny(string $code, string $message, ?string $role = null, ?CarbonInterface $opensAt = null, ?CarbonInterface $closesAt = null): self
    {
        return new self(false, $role, $code, $message, $opensAt, $closesAt);
    }

    public function status(): int
    {
        return match ($this->code) {
            'not_participant' => 403,
            'too_early', 'closed', 'ended', 'lesson_over', 'not_confirmed' => 409,
            default => 403,
        };
    }

    public function jsonSerialize(): array
    {
        return [
            'allowed' => $this->allowed,
            'role' => $this->role,
            'code' => $this->code,
            'message' => $this->message,
            'opens_at' => $this->opensAt?->toIso8601String(),
            'closes_at' => $this->closesAt?->toIso8601String(),
        ];
    }
}
