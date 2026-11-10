<?php

namespace App\Domain\Academic\Support;

use App\Domain\Academic\Enums\TeachingProficiency;

final readonly class TeachingLanguage
{
    public function __construct(
        public string $code,
        public string $nameEn,
        public TeachingProficiency $proficiency,
        public bool $verified,
    ) {}
}
