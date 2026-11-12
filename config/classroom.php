<?php

/*
 * R-47 live classroom (classroom agent, D-800 onward). docs/R47_CLASSROOM.md explains every value.
 */

$list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    // The room opens this many minutes before the lesson starts, and closes this many after it ends.
    'open_minutes_before' => (int) env('CLASSROOM_OPEN_MINUTES_BEFORE', 10),
    'grace_minutes_after' => (int) env('CLASSROOM_GRACE_MINUTES_AFTER', 30),

    // Peer-to-peer mesh: every browser sends its video to every other one, so the uplink limits the room size.
    // Six people (a tutor and five learners) is the most a home connection carries; Dot.Engage measured six meshed.
    'max_in_room' => (int) env('CLASSROOM_MAX_IN_ROOM', 6),

    // A joined person not heard from for this long counts as gone (attendance stops counting at their last poll).
    'presence_seconds' => (int) env('CLASSROOM_PRESENCE_SECONDS', 30),

    // Signalling messages (offers, answers, ICE candidates) are deleted after this many seconds: once two browsers are
    // connected the messages are useless, and they contain network addresses.
    'signal_ttl_seconds' => (int) env('CLASSROOM_SIGNAL_TTL_SECONDS', 120),

    'rtc' => [
        // STUN tells a browser its public address so two browsers on different networks can find a direct path. The
        // STUN server sees each participant's IP address and port, never audio, video, names or accounts. Default:
        // Google's public STUN (D-804, the privacy trade-off is in docs/R47_CLASSROOM.md); set CLASSROOM_STUN_URLS to
        // your own server (a coturn TURN server also answers STUN), or to "none" to send nothing (same network only).
        'stun_urls' => env('CLASSROOM_STUN_URLS') === 'none' ? [] : ($list(env('CLASSROOM_STUN_URLS', '')) ?: ['stun:stun.l.google.com:19302']),
        // TURN relays media when no direct path exists. No TURN server exists yet (owner item). With a shared secret
        // (coturn use-auth-secret) credentials are minted per person and expire.
        'turn_urls' => $list(env('CLASSROOM_TURN_URLS', '')),
        'turn_secret' => env('CLASSROOM_TURN_SECRET'),
        'turn_ttl' => (int) env('CLASSROOM_TURN_TTL', 3600),
        'turn_username' => env('CLASSROOM_TURN_USERNAME'),
        'turn_credential' => env('CLASSROOM_TURN_CREDENTIAL'),
    ],

    'chat' => [
        'max_length' => 1000,
        'per_minute' => 20,
    ],

    'files' => [
        'max_kb' => 10240,
    ],

    'board' => [
        'max_pages' => 20,
        'max_points' => 2000,
        'max_text' => 200,
        'snapshot_max_kb' => 4096,
    ],

    'notes' => [
        'max_length' => 20000,
    ],

    // Hosts a tutor's fallback meeting link may point at (R-47 security S-09). A link to a chat app (wa.me, t.me)
    // or a personal page would hand a learner, possibly a child, an off-platform contact. A host matches itself and
    // its subdomains (us02web.zoom.us). Comma-separated override: CLASSROOM_MEETING_HOSTS.
    'meeting_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLASSROOM_MEETING_HOSTS',
        'meet.google.com,zoom.us,teams.microsoft.com,teams.live.com,whereby.com,meet.jit.si,webex.com'))))),
];
