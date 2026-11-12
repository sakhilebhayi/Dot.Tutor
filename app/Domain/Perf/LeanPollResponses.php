<?php

namespace App\Domain\Perf;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Polling answers are small (a 304, or a few hundred bytes of JSON) but each carried about 1.5 KB of headers: a
 * fresh XSRF-TOKEN cookie, a re-sent session cookie and the Content-Security-Policy. A learner in a live lesson polls
 * every 1 to 2 seconds, so on a phone that is most of the data the lesson's signalling costs (R-47 D-1608,
 * docs/R47_PERF.md; the same treatment as Dot.Ehail R-46 D-1506).
 *
 * On the polling routes only, this removes:
 *  - the XSRF-TOKEN cookie (these clients send the page's csrf-token meta value; the cookie set by the page stays);
 *  - the session cookie when the session was not written this request (LazyTouchDatabaseSessionHandler skipped it).
 *    The handler still writes, and the cookie is still re-sent, at least once a minute, so its expiry keeps sliding;
 *  - the CSP header on JSON and 304 answers (a policy governs documents; it does nothing for a JSON body).
 * Every other route and every other header is untouched.
 */
class LeanPollResponses
{
    /** @var list<string> */
    public const ROUTES = [
        'classroom.api.poll',
        'classroom.api.signal',
        'classroom.api.board',
        'rooms.activity',
        'community.api.activity',
        'comms.inbox.summary',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $name = $request->route()?->getName();

        if ($name === null || ! in_array($name, self::ROUTES, true)) {
            return $response;
        }

        $sessionCookie = (string) config('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            $drop = $cookie->getName() === 'XSRF-TOKEN'
                || ($cookie->getName() === $sessionCookie && LazyTouchDatabaseSessionHandler::$skippedWrite && $request->cookies->has($sessionCookie));

            if ($drop) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        if ($response->getStatusCode() === 304 || str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $response->headers->remove('Content-Security-Policy-Report-Only');
            $response->headers->remove('Content-Security-Policy');
        }

        return $response;
    }
}
