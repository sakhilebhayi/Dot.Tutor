<?php

namespace App\Domain\Safety\Contracts;

use App\Domain\Safety\Enums\AgeBand;

/** A room or group (community, wave 2) that says which age bands it is for and whether it is paid. */
interface AgeGatedSpace
{
    /** @return list<AgeBand> */
    public function safetyAgeBands(): array;

    public function safetyIsPaid(): bool;

    /** @return list<int> members in their own right (learners and tutors), not watchers */
    public function safetyMemberIds(): array;
}
