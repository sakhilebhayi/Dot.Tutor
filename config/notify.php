<?php

/*
|--------------------------------------------------------------------------
| Dot.Notify (ecosystem notifications)
|--------------------------------------------------------------------------
| Dot.Notify's documented ingest contract (Dot.Notify main: routes/api.php
| POST /api/ingest/events, app/Services/Notify/IngestSignature.php): the
| caller signs "{timestamp}.{raw body}" with HMAC-SHA256 using its platform
| secret (NOTIFY_INGEST_SECRET_TUTOR in Dot.Notify's .env), and Notify routes
| by InfoDot company. Both the URL and the secret must be set or nothing is
| sent (off by default). Notify only sends when the company has a rule for
| the event; comms records Notify's answer (routed, no_rule, ...).
*/

return [
    'url' => env('NOTIFY_INGEST_URL'),
    'secret' => env('NOTIFY_INGEST_SECRET'),
    'platform' => env('NOTIFY_PLATFORM_KEY', 'tutor'),
    'timeout' => 5,

    /*
    | Comms type => Dot.Notify event name. Only these are mirrored, only to adult recipients, and only with ids,
    | times and titles (never a minor's details, never message text). Dot.Notify's catalogue has no tutor.*
    | entries yet (owner item), so Notify accepts them without its per-event data filter.
    */
    'events' => [
        'lesson_confirmed' => 'tutor.lesson.confirmed',
        'lesson_cancelled' => 'tutor.lesson.cancelled',
        'lesson_reminder' => 'tutor.lesson.reminder',
        'lesson_requested' => 'tutor.lesson.requested',
        'payment_received' => 'tutor.payment.received',
        'payout_paid' => 'tutor.payout.paid',
    ],
];
