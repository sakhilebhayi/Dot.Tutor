<?php

namespace App\Domain\Safety\Contracts;

use App\Domain\Safety\Support\Decision;
use App\Models\User;

/**
 * The one question every talk, meet, pay and publish action asks, server-side, before it acts.
 *
 * Abilities: book, pay, message, join_room, meet_live, publish, view_contact, upload (see the ABILITIES list).
 * Subjects: a User (the other person), a TutorProfile, a TutorSession, a Conversation, any model implementing
 * InvolvesPeople or AgeGatedSpace, or null. Context keys used: 'record' (meet_live), 'commercial' and
 * 'audience' (publish), 'kind' (pay: purchase or paid_room), 'context_type' (message).
 *
 * A Decision may allow an action and still carry a requirement (guardian approval): the caller must then hold
 * the action until GuardianApprovals reports it approved.
 */
interface SafeguardingGate
{
    public const ABILITIES = ['book', 'pay', 'message', 'join_room', 'meet_live', 'publish', 'view_contact', 'upload'];

    /** @param array<string, mixed> $context */
    public function allows(?User $actor, string $ability, mixed $subject = null, array $context = []): Decision;
}
