<?php

namespace App\Domain\Safety\Enums;

enum ReportCategory: string
{
    case Harassment = 'harassment';
    case InappropriateContactWithMinor = 'inappropriate_contact_minor';
    case CheatingRequest = 'cheating_request';
    case Copyright = 'copyright';
    case Misinformation = 'misinformation';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Harassment => 'Harassment or bullying',
            self::InappropriateContactWithMinor => 'Inappropriate contact with a learner under 18',
            self::CheatingRequest => 'Asking someone to cheat (do an exam or assignment for them)',
            self::Copyright => 'Copyright infringement',
            self::Misinformation => 'Wrong or misleading educational content',
            self::Spam => 'Spam or scam',
            self::Other => 'Something else',
        };
    }

    /** Child-safety reports take the fast path: critical priority and an incident with an escalation timer. */
    public function isChildSafety(): bool
    {
        return $this === self::InappropriateContactWithMinor;
    }
}
