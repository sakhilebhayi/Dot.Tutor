<?php

namespace App\Domain\Safety\Services;

/**
 * Hides contact details (email addresses, phone numbers, social handles, links to outside chat, social and meeting
 * apps) in text that involves a learner under 18, and says why. Pattern based: it catches the common forms, not a
 * determined adult spelling a number out in words; that is what reporting and moderation are for.
 */
class ContactRedactor
{
    public const NOTE = 'Contact details were hidden because a learner under 18 is part of this. Keep lessons and messages on Dot.Tutor, where a guardian can see them.';

    private const CHAT_DOMAINS = 'wa\.me|chat\.whatsapp\.com|api\.whatsapp\.com|whatsapp\.com|t\.me|telegram\.(?:me|org|dog)'
        .'|signal\.(?:me|group)|snapchat\.com|instagram\.com|instagr\.am|tiktok\.com|facebook\.com|fb\.me|fb\.com|m\.me'
        .'|messenger\.com|discord\.gg|discord\.com|x\.com|twitter\.com|threads\.net|linkedin\.com|wechat\.com|line\.me'
        .'|kik\.me|zoom\.us|meet\.google\.com|teams\.microsoft\.com|teams\.live\.com|skype\.com|join\.skype\.com'
        // Link shorteners and link pages hide any of the above (R-47 security S-15).
        .'|discordapp\.com|bit\.ly|tinyurl\.com|t\.co|goo\.gl|is\.gd|cutt\.ly|rb\.gy|linktr\.ee|beacons\.ai';

    /** @return array{text: string, redactions: array<string, int>} */
    public function redact(string $text): array
    {
        $counts = [];
        $text = $this->normalise($text);

        $replace = function (string $pattern, string $label, string $kind, string $subject, ?callable $accept = null) use (&$counts): string {
            return (string) preg_replace_callback($pattern, function (array $m) use ($label, $kind, $accept, &$counts) {
                if ($accept !== null && ! $accept($m)) {
                    return $m[0];
                }
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;

                return $label;
            }, $subject);
        };

        $text = $replace('~(?:https?://)?(?:www\.)?(?:'.self::CHAT_DOMAINS.')(?:/[^\s<>"\']*)?~i', '[link hidden]', 'link', $text);
        $text = $replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email hidden]', 'email', $text);
        $text = $replace('/(?<![\w@])@[A-Za-z0-9_.]{2,30}(?![\w@])/', '[handle hidden]', 'handle', $text);
        $text = $replace(
            '/(?<![\w+])\+?\d[\d\s().\-\/_*]{7,}\d(?!\w)/',
            '[phone hidden]',
            'phone',
            $text,
            fn (array $m) => ($digits = strlen(preg_replace('/\D/', '', $m[0]))) >= 9 && $digits <= 15,
        );
        $text = $replace(
            '/\b(?:whatsapp|whats\s?app|snapchat|snap|insta(?:gram)?|telegram|tiktok|signal|discord|wechat|kik|facebook|fb)'
            .'(?:\s+(?:me|id|name|handle|username|is|on|at))*\s*[:\-]?\s*([A-Za-z][A-Za-z0-9_.]*[0-9_.][A-Za-z0-9_.]*)/i',
            '[handle hidden]',
            'handle',
            $text,
        );

        // "my insta is coolkid", "telegram: mathsdude": a named chat app followed by a plain name (S-15).
        $text = $replace(
            '/\b(?:whatsapp|whats\s?app|snapchat|insta(?:gram)?|telegram|tiktok|discord|wechat|kik)'
            .'(?:\s+(?:id|name|handle|username|account|number))?\s*(?:is|:|-|=)\s*([A-Za-z][A-Za-z0-9_.]{2,})/i',
            '[handle hidden]',
            'handle',
            $text,
        );

        return ['text' => $text, 'redactions' => $counts];
    }

    /**
     * Fold the look-alikes that slip past ASCII patterns (R-47 security S-15): compatibility forms (fullwidth digits
     * and at-signs), invisible characters placed between digits, and digits from other scripts.
     */
    private function normalise(string $text): string
    {
        if (! preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }

        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        $text = (string) preg_replace('/[\x{00AD}\x{180E}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text);

        return (string) preg_replace_callback('/\p{Nd}/u', function (array $m): string {
            $value = class_exists(\IntlChar::class) ? \IntlChar::charDigitValue($m[0]) : -1;

            return $value >= 0 && $value <= 9 ? (string) $value : $m[0];
        }, $text);
    }

    public function containsContactDetails(string $text): bool
    {
        return $this->redact($text)['redactions'] !== [];
    }
}
