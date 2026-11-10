<?php

namespace App\Domain\Marketplace\Contracts;

/**
 * Approved tutors with their scope, languages and verification level, for
 * other domains (community, learning, ai). Provided by marketplace.
 */
interface TutorDirectory
{
    /**
     * @param  array{subject_id?: int, level_id?: int, language?: string, limit?: int}  $filters
     * @return list<array<string, mixed>>
     */
    public function approved(array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function find(int $tutorProfileId): ?array;
}
