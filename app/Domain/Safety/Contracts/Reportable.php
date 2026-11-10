<?php

namespace App\Domain\Safety\Contracts;

use App\Models\User;

/** Optional on a reportable model: lets a report name the person responsible and keep a snapshot. */
interface Reportable
{
    public function reportedUser(): ?User;

    /** @return list<int> the people in the reported exchange (used to flag reports involving minors) */
    public function involvedUserIds(): array;

    /** @return array<string, mixed> what the reporter could see, frozen at report time */
    public function reportSnapshot(): array;
}
