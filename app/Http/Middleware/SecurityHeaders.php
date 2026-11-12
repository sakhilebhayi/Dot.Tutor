<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections on every response (R-47 security S-29, D-1514). A header the route already set is left alone
 * (private files send their own sandboxing policy).
 *
 * The Content-Security-Policy is ENFORCED and nonce-based: every page script is a built module from this origin or
 * an inline script carrying the per-request nonce (Vite::cspNonce(), which @vite, Livewire and the layouts use).
 * 'unsafe-eval' is added only on pages that run Livewire (its bundled Alpine evaluates expressions). Styles keep
 * 'unsafe-inline' (style attributes throughout the views; no script can run from a style). Nothing loads from
 * another host: fonts, icons and scripts are self-hosted (ds). The classroom page alone may use the camera, the
 * microphone and screen sharing (WebRTC; STUN and peer connections are not governed by connect-src). Forms may
 * send the browser on to Paystack's checkout and to InfoDot. SECURITY_CSP_REPORT_ONLY=true is the escape hatch.
 */
class SecurityHeaders
{
    /** Route names whose page may ask for the camera, microphone and screen (the live classroom). */
    private const MEDIA_ROUTES = ['classroom.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $media = in_array($request->route()?->getName(), self::MEDIA_ROUTES, true);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => $media
                ? 'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=()'
                : 'camera=(), microphone=(), display-capture=(), geolocation=(), payment=(), usb=()',
        ];

        $headers[config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy']
            = $this->policy($nonce, $this->runsLivewire($response));

        if ($request->isSecure() && config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce, bool $livewire): string
    {
        $formTargets = array_filter([
            'https://checkout.paystack.com',
            $this->origin((string) config('ecosystem.infodot.redeem_url')),
            $this->origin((string) config('ecosystem.platforms.infodot.url', '')),
        ]);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".($livewire ? " 'unsafe-eval'" : ''),
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'none'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' ".implode(' ', array_unique($formTargets)),
            'report-uri /csp-report',
        ]);
    }

    private function runsLivewire(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type');

        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return false;
        }

        $content = method_exists($response, 'getContent') ? (string) $response->getContent() : '';

        return str_contains($content, 'livewireScriptConfig') || str_contains($content, 'data-update-uri=');
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) && in_array($parts['scheme'], ['https', 'http'], true)
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
    }
}
