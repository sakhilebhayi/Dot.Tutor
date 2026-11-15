<?php

namespace App\Support\Search;

use Illuminate\Support\HtmlString;

/** Wraps the typed words in <mark>, escaping everything else, for the full results page (the header dropdown does it in script). */
final class Highlight
{
    /** @param  list<string>  $words */
    public static function mark(string $text, array $words): HtmlString
    {
        $words = array_values(array_filter(array_map('trim', $words), fn (string $w) => $w !== ''));
        if ($words === []) {
            return new HtmlString(e($text));
        }

        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(fn (string $w) => preg_quote($w, '/'), $words)).')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        $out = '';
        foreach ($parts as $part) {
            $out .= preg_match($pattern, $part) === 1 ? '<mark>'.e($part).'</mark>' : e($part);
        }

        return new HtmlString($out);
    }
}
