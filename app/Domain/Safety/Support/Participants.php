<?php

namespace App\Domain\Safety\Support;

use App\Domain\Safety\Contracts\InvolvesPeople;

/** A plain list of people, for asking the gate about a conversation that does not exist yet. */
final class Participants implements InvolvesPeople
{
    /** @param list<int> $ids */
    public function __construct(private readonly array $ids) {}

    public function safetyParticipantIds(): array
    {
        return array_values(array_map('intval', $this->ids));
    }
}
