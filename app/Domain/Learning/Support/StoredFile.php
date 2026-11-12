<?php

namespace App\Domain\Learning\Support;

final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $mime,
        public int $size,
        public string $sha256,
        public string $originalName,
    ) {}
}
