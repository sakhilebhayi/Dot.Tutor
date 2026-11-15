<?php

namespace App\Support\Search;

/** One row of the header search: a title, where it goes, an optional reference (a date, a code) and a short tag naming what it is. */
final readonly class SearchHit
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $ref = null,
        public ?string $tag = null,
    ) {}

    /** @return array{label: string, href: string, code: ?string, tag: ?string} the shape js/dot-search-results.js prints */
    public function toRow(): array
    {
        return ['label' => $this->title, 'href' => $this->url, 'code' => $this->ref, 'tag' => $this->tag];
    }
}
