<?php

use App\Sso\TutorProvisioner;

/*
 * Dot.Tutor's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 *
 * CHILD SAFEGUARDING (R-54): InfoDot registration collects no age. `auto_provision` is true ONLY so the package asks
 * TutorProvisioner::create(), which refuses everyone except an invited guardian (App\Sso\NewAccountAdmission). It is
 * deliberately NOT tied to INFODOT_PROVISION_USERS and has no switch that opens accounts to unknown people.
 */
return [
    'provisioner' => TutorProvisioner::class,

    'auto_provision' => true,

    // /dashboard sends each person to their role home (RoleHome); a deep link, when there is one, wins.
    'landing' => '/dashboard',

    // A deep link may open any page of the signed-in app. The guardian invitation (a signed URL with its query) must
    // survive untouched, so nothing here rewrites a path or query; machine paths and the auth pages are denied.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|api|up|ready|csp-report|sanctum|webhooks?|broadcasting)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. Tutor's machine doors and signed links are outside the pages the package takes over.
    'allow_paths' => [],
];
