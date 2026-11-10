<?php

namespace App\Domain\Academic\I18n;

/**
 * Formulas, numbers and notation must survive translation unchanged (brief
 * section 10). Deterministic check: every LaTeX segment, number, chemical
 * formula with a digit (H2O, CO2) and expression containing an operator
 * (x^2, a=b+c) in the original must appear verbatim in the translation.
 * It cannot judge meaning; it catches a dropped or rewritten formula.
 */
final class NotationGuard
{
    /** @return list<string> tokens present in the original and missing from the translation */
    public static function missing(string $original, string $translation): array
    {
        return array_values(array_filter(
            self::tokens($original),
            function (string $token) use ($translation): bool {
                if (str_contains($translation, $token)) {
                    return false;
                }
                // A decimal comma and a decimal point are both correct, depending on the language (3.5 / 3,5).
                $swapped = preg_replace_callback('/(\d)([.,])(\d)/', fn (array $m) => $m[1].($m[2] === '.' ? ',' : '.').$m[3], $token);

                return $swapped === $token || ! str_contains($translation, (string) $swapped);
            },
        ));
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $tokens = [];

        // LaTeX: $...$, \(...\), \[...\]
        preg_match_all('/\$[^$]+\$|\\\\\(.+?\\\\\)|\\\\\[.+?\\\\\]/s', $text, $latex);
        foreach ($latex[0] as $segment) {
            $tokens[] = $segment;
            $text = str_replace($segment, ' ', $text);
        }

        // Expressions with an operator, e.g. x^2, 2x+3=7, f(x)=x^2-1, a^2 + b^2 = c^2. A run must hold
        // "=" or "^", or a digit and an operator, so "well-known" and "and/or" are not formulas.
        preg_match_all('/(?:[A-Za-z0-9().,^]+\s*[=+\-*\/<>^]\s*)+[A-Za-z0-9().,^]+/', $text, $expressions);
        foreach ($expressions[0] as $expression) {
            $expression = rtrim($expression, '.,');
            if (! preg_match('/[=^]/', $expression) && ! (preg_match('/\d/', $expression) && preg_match('/[+*\/<>]|\d\s*-\s*\d/', $expression))) {
                continue;
            }
            $tokens[] = $expression;
            $text = str_replace($expression, ' ', $text);
        }

        // Chemical formulas with a digit: H2O, CO2, C6H12O6
        preg_match_all('/\b(?:[A-Z][a-z]?\d*){2,}\b/', $text, $chemical);
        foreach ($chemical[0] as $formula) {
            if (preg_match('/\d/', $formula)) {
                $tokens[] = $formula;
            }
        }

        // Numbers, keeping decimal separators as written
        preg_match_all('/(?<![A-Za-z])\d+(?:[.,]\d+)?/', $text, $numbers);
        foreach ($numbers[0] as $number) {
            $tokens[] = $number;
        }

        return array_values(array_unique($tokens));
    }
}
