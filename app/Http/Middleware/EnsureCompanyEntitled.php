<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50).
 *
 * InfoDot decides whether a company holds Dot.Tutor. A person who is already signed in must stop working when that
 * ends, so each page and action request in the signed-in area asks (through a ten-minute cache) whether the person's
 * current company still holds the platform. A clear "no" answers with the plain refusal page (HTTP 403, JSON for
 * JSON callers): nothing is read, written, booked or paid. Anything else, including every kind of failure, lets the
 * request through exactly as before.
 *
 * Nothing already made is touched: bookings, lessons in progress and payments stay as they are. This is attached only
 * to the signed-in route groups (alias `entitled`) and to Jetstream's own pages; it is not on sign-in, sign-out, the
 * handoff receiver, health checks, the payment webhook, scheduled jobs, the CSP report or any public page.
 *
 * The refusal is rendered in place with a 403 and never redirects, so the four role homes (/learn, /teach, /ops,
 * /guardian) cannot loop when a person is refused.
 */
class EnsureCompanyEntitled
{
    /** Routes inside the checked groups that must keep working for a person whose company is refused. */
    private const EXEMPT = [
        'current-team.update', // switching to another company
        'profile.show',        // the person's own account
        'terms.show', 'policy.show', 'team-invitations.accept',
        'security.export', 'security.close', // the person's own data rights (export, close the account)
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(private readonly EntitlementClient $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $this->company($request, $user);

        // No team, a personal workspace, or a team InfoDot does not know: the read endpoint is never asked. What
        // InfoDot said about the person's personal workspace at sign-in decides: exactly false is a refusal
        // (individuals hold the free core only); true, or nothing remembered, carries on.
        if (! $team || $team->personal_team || blank($team->infodot_team_uuid)) {
            if (! $request->hasSession() || $request->session()->get(self::PERSONAL) !== false) {
                return $next($request);
            }

            return $this->refuse($request, fn () => EntitlementRefusal::response(
                'company_required',
                null,
                $user->allTeams()->reject(fn ($other) => $other->id === $team?->id || $other->personal_team || blank($other->infodot_team_uuid)),
            ), 'company_required');
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, fn () => EntitlementRefusal::response(
            $answer['status'],
            $team->name,
            $user->allTeams()->reject(fn ($other) => $other->id === $team->id),
        ), $answer['status']);
    }

    /**
     * Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let a
     * component update run. On a Livewire update, upload or JSON request the refusal must abort: nothing runs and no
     * component or data comes back.
     */
    private function refuse(Request $request, Closure $page, string $reason): Response
    {
        if ($request->headers->has('X-Livewire') || request()->headers->has('X-Livewire') || $request->expectsJson() || $request->is('api/*')) {
            abort(403, 'Your company does not hold Dot.Tutor right now ('.($reason !== '' ? $reason : 'not switched on').'). Nothing was changed.');
        }

        return $page();
    }

    /** The company the request is about: the one in the address when the person belongs to it, else their current one. */
    private function company(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named;
        }

        $current = $user->currentTeam;

        return $current instanceof Team ? $current : null;
    }
}
