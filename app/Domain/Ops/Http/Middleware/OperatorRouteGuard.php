<?php

namespace App\Domain\Ops\Http\Middleware;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\RoutePermissions;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\MessageAttachment;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role check for operator routes that do not sit behind the `operator` middleware (routes guarded by a `can:` gate
 * or inside the controller: community held posts, learning library moderation, money finance, the AI log), and
 * narrowing for shared routes an operator may open on someone else's behalf (RoutePermissions::NON_PARTY).
 * Pushed onto the web group by TutorOpsServiceProvider. It only ever narrows: a signed-out request passes through
 * to the route's own authentication, and a person who is a party to a conversation is never affected.
 */
class OperatorRouteGuard
{
    public function __construct(private readonly OpsAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;
        $user = $request->user();

        if ($name === null || ! $user instanceof User) {
            return $next($request);
        }

        $needs = RoutePermissions::match($name);
        if ($needs !== null) {
            abort_unless($this->access->isOperator($user), 403);
            abort_unless($this->access->canAny($user, $needs), 403, 'Your operator role does not include this.');

            return $next($request);
        }

        if (isset(RoutePermissions::NON_PARTY[$name]) && $this->access->isOperator($user) && ! $this->isParty($user, $route->parameters())) {
            abort_unless($this->access->canAny($user, RoutePermissions::NON_PARTY[$name]), 403, 'Your operator role does not include this.');
        }

        return $next($request);
    }

    /** @param array<string, mixed> $parameters */
    private function isParty(User $user, array $parameters): bool
    {
        $conversation = $parameters['conversation'] ?? null;
        $attachment = $parameters['attachment'] ?? null;

        if ($attachment !== null) {
            $attachment = $attachment instanceof MessageAttachment ? $attachment : MessageAttachment::query()->find($attachment);
            $conversation = $attachment?->message?->conversation_id;
        }

        if ($conversation !== null) {
            $conversation = $conversation instanceof Conversation ? $conversation : Conversation::query()->find($conversation);

            return $conversation !== null && $conversation->participantFor($user) !== null;
        }

        return false;
    }
}
