<?php

namespace App\Support;

/**
 * Where "Continue with InfoDot" goes (fix round D-2301). InfoDot signs the person in and posts a one-time token to
 * /auth/ecosystem, which this app redeems server to server with its service secret. Without both a launch address
 * and the secret that round trip cannot finish, so the sign-in page shows no InfoDot button at all.
 */
class InfoDotSignIn
{
    public static function launchUrl(): ?string
    {
        $url = config('ecosystem.infodot.launch_url');
        $secret = config('ecosystem.infodot.secret');

        if (! is_string($url) || trim($url) === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        // Plain http only away from production (the local E2E stub); a live sign-in never leaves https.
        if ($scheme === 'http' && app()->environment('production')) {
            return null;
        }

        return $url;
    }
}
