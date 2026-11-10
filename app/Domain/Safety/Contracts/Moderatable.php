<?php

namespace App\Domain\Safety\Contracts;

use App\Models\User;

/** A model a moderator can hide and restore. Models without it can be reported but not hidden from here. */
interface Moderatable
{
    public function moderationHide(User $operator): void;

    public function moderationRestore(User $operator): void;
}
