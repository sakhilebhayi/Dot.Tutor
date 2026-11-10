<?php

namespace App\Domain\Safety\Contracts;

/** A subject the gate can ask "who else is in this?" (an offering, a group lesson, a course with live parts). */
interface InvolvesPeople
{
    /** @return list<int> */
    public function safetyParticipantIds(): array;
}
