<?php

namespace App\Domain\Ai\Guardrails;

/**
 * A small, deterministic net for words that suggest a person may be at risk (self-harm, abuse, danger). A hit flags
 * the interaction for the safeguarding review queue and adds fixed help text to the answer; it never blocks the
 * person from being answered. English and a few Afrikaans phrases only: it will miss things (documented), which is
 * why every answer carries a report button and the model is told what to do as well.
 */
final class SafeguardingSignals
{
    private const PATTERNS = [
        'self_harm' => '/\b(kill\s+myself|end\s+my\s+life|want\s+to\s+die|suicid\w*|self[-\s]?harm|cut(ting)?\s+myself|hurt\s+myself|selfmoord|my\s+lewe\s+neem)\b/iu',
        'abuse' => '/\b(he|she|they|my\s+\w+)\s+(touches|touched|hits|hit|beats|beat|hurts|hurt)\s+me\b|\b(sexual(ly)?\s+abus\w*|molest\w*|rape[ds]?|groom(ed|ing)?)\b|\bmisbruik\w*\b/iu',
        'danger' => '/\b(not\s+safe\s+at\s+home|scared\s+to\s+go\s+home|someone\s+is\s+following\s+me|asked\s+me\s+to\s+send\s+(a\s+)?(photo|picture|pic)s?\s+of\s+myself|keep\s+(it|this)\s+(a\s+)?secret\s+from\s+my\s+(parents|mom|mum|dad))\b/iu',
    ];

    /** @return string|null the first signal kind found */
    public static function detect(string $text): ?string
    {
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Fixed help text shown with the answer when a signal fires. South African numbers: Childline 116 (free, for
     * children), SAPS emergency 10111, 112 from a mobile phone. Owner item: confirm with a safeguarding lead.
     */
    public static function helpText(string $band): string
    {
        return $band === 'adult'
            ? 'If you or someone else is in danger, call 10111 (police) or 112 from a mobile phone. You can also report this conversation to Dot.Tutor\'s safety team with the report button.'
            : 'If you are not safe or something is worrying you, please tell a trusted adult. You can call Childline free on 116, any time. In an emergency call 10111 or 112 from a mobile phone. You can also use the report button to tell Dot.Tutor\'s safety team.';
    }
}
