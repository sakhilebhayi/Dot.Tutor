<?php

/*
|--------------------------------------------------------------------------
| R-47 comms: notifications, reminders, channels (docs/R47_COMMS.md)
|--------------------------------------------------------------------------
| Every notification is written to the in-app inbox first; push, email, SMS
| and Dot.Notify are copies recorded in comms_deliveries with their real
| outcome. Categories group notification types for people's choices.
|
| mandatory: cannot be switched off (safety, payments, guardian approvals).
| urgent: ignores quiet hours (safeguarding alerts only).
| marketing: never sent to a minor, in any channel, and off by default.
*/

return [
    'categories' => [
        'safety' => [
            'label' => 'Safety and safeguarding',
            'description' => 'Reports you made, warnings about your account and safeguarding alerts.',
            'channels' => ['push', 'email', 'sms'],
            'defaults' => ['push' => true, 'email' => true, 'sms' => true],
            'mandatory' => true,
            'urgent' => true,
            'mandatory_reason' => 'Safety messages always reach you so that nothing about a child or an account goes unseen.',
        ],
        'guardian' => [
            'label' => 'Guardian approvals',
            'description' => 'Requests waiting for a parent or guardian, and their decisions.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
            'mandatory' => true,
            'mandatory_reason' => 'A request waiting for a guardian lapses if nobody sees it, so these always reach you.',
        ],
        'payments' => [
            'label' => 'Payments and refunds',
            'description' => 'Payments received or declined, receipts and refunds.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
            'mandatory' => true,
            'mandatory_reason' => 'You always hear about money taken or returned.',
        ],
        'lessons' => [
            'label' => 'Lessons and bookings',
            'description' => 'Requests, acceptances, confirmations, cancellations and changes to your lessons.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'reminders' => [
            'label' => 'Lesson reminders',
            'description' => 'A day before and an hour before each confirmed lesson.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'learning' => [
            'label' => 'Courses and assignments',
            'description' => 'Enrolments, work your tutor set, and reminders before it is due.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'teaching' => [
            'label' => 'Teaching',
            'description' => 'For tutors: new learners in your courses and sales of your materials.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => true],
        ],
        'tutor_account' => [
            'label' => 'Tutor application and verification',
            'description' => 'Decisions on your tutor application and your qualifications.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'earnings' => [
            'label' => 'Earnings and payouts',
            'description' => 'Payouts being prepared, paid or failed.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => true],
        ],
        'library' => [
            'label' => 'Study material you shared',
            'description' => 'Reviews, take-downs and restorations of resources you uploaded.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => true],
        ],
        'community' => [
            'label' => 'Study groups',
            'description' => 'Updates from study groups you belong to.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => false],
        ],
        'updates' => [
            'label' => 'News from Dot.Tutor',
            'description' => 'Occasional news about new features. Never sent to learners under 18.',
            'channels' => ['email'],
            'defaults' => ['email' => false],
            'marketing' => true,
        ],
        'general' => [
            'label' => 'Other notices',
            'description' => 'Anything else about your account.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => true],
        ],
    ],

    /*
    | Notification type => category. Types not listed fall into "general".
    */
    'types' => [
        // Bookings (marketplace)
        'lesson_requested' => 'lessons',
        'lesson_awaiting_guardian' => 'guardian',
        'lesson_accepted' => 'lessons',
        'lesson_declined' => 'lessons',
        'lesson_confirmed' => 'lessons',
        'lesson_cancelled' => 'lessons',
        'lesson_expired' => 'lessons',
        'lesson_changed' => 'lessons',
        'lesson_completed' => 'lessons',
        'lesson_review_invitation' => 'lessons',
        'lesson_reminder' => 'reminders',
        'seat_confirmed' => 'lessons',
        'seat_cancelled' => 'lessons',
        // Safety
        'guardian_approval_requested' => 'guardian',
        'guardian_approval_decided' => 'guardian',
        'account_warned' => 'safety',
        'incident_alert' => 'safety',
        'report_updated' => 'safety',
        // Money
        'payment_received' => 'payments',
        'payment_failed' => 'payments',
        'refund_processed' => 'payments',
        'sale_made' => 'teaching',
        'payout_drafted' => 'earnings',
        'payout_paid' => 'earnings',
        'payout_failed' => 'earnings',
        // Learning
        'course_enrolled' => 'learning',
        'course_new_learner' => 'teaching',
        'assignment_set' => 'learning',
        'assignment_due' => 'learning',
        'resource_approved' => 'library',
        'resource_taken_down' => 'library',
        'resource_restored' => 'library',
        // Marketplace accounts
        'tutor_application_decided' => 'tutor_account',
        'tutor_verification_decided' => 'tutor_account',
        // Community (wave 2 emits StudyGroupUpdated)
        'study_group_update' => 'community',
        // Product news (marketing; never to minors)
        'product_update' => 'updates',
    ],

    /*
    | Notifications other parts of the app already write with $user->notify() (database channel), mapped to a
    | comms type so the inbox, preferences and push/email copies treat them like comms' own.
    */
    'source_types' => [
        App\Notifications\TutorProfileDecision::class => 'tutor_application_decided',
        App\Notifications\SessionCancelledByModeration::class => 'lesson_cancelled',
        App\Domain\Safety\Notifications\GuardianApprovalRequested::class => 'guardian_approval_requested',
        App\Domain\Safety\Notifications\GuardianApprovalDecided::class => 'guardian_approval_decided',
        App\Domain\Safety\Notifications\AccountWarned::class => 'account_warned',
        App\Domain\Safety\Notifications\IncidentAlert::class => 'incident_alert',
    ],

    /*
    | Source notifications that send their own email (via => mail): comms records that email instead of making a
    | second copy.
    */
    'source_sends_own_email' => [
        App\Domain\Safety\Notifications\GuardianApprovalRequested::class,
    ],

    // Types that stay in the inbox only (no push, email or SMS copies).
    'in_app_only_types' => [],

    'push' => [
        // Base64url raw keys; `php artisan comms:vapid-keys` prints a pair. Without both, push is off everywhere.
        'public_key' => env('COMMS_VAPID_PUBLIC_KEY'),
        'private_key' => env('COMMS_VAPID_PRIVATE_KEY'),
        'subject' => env('COMMS_VAPID_SUBJECT', 'mailto:no-reply@infodot.co.za'),
        // Only these push services are ever called (a forged subscription cannot point the server elsewhere).
        'allowed_hosts' => array_filter(explode(',', (string) env('COMMS_PUSH_ALLOWED_HOSTS',
            'fcm.googleapis.com,updates.push.services.mozilla.com,push.services.mozilla.com,web.push.apple.com,notify.windows.com,push.apple.com'))),
        'ttl' => 3600 * 12,
        'timeout' => 5,
        'max_subscriptions_per_user' => 10,
    ],

    'sms' => [
        // No SMS provider is built (decision D-1006). The adapter answers "not configured" and nothing is collected.
        'driver' => env('COMMS_SMS_DRIVER'),
    ],

    'quiet_hours' => [
        // Push and SMS wait until quiet hours end (urgent safety alerts excepted). People change these.
        'enabled' => true,
        'start' => '21:00',
        'end' => '07:00',
    ],

    'reminders' => [
        // Minutes before the lesson; the window is how late a reminder may still go out (cron gaps).
        'lesson' => [
            '24h' => ['minutes' => 1440, 'window' => 120],
            '1h' => ['minutes' => 60, 'window' => 50],
        ],
        // No reminder when the lesson was confirmed this recently (the confirmation itself was the message).
        'skip_if_confirmed_within_minutes' => 30,
        'assignment_due_minutes' => 1440,
        'assignment_window' => 180,
    ],

    'delivery' => [
        'batch' => 100,
        'lease_seconds' => 120,
        'backoff' => [60, 300, 900, 3600],
        'max_attempts' => 5,
        'retention_days' => (int) env('COMMS_RETENTION_DAYS', 120),
        // A lesson push that waited this long (quiet hours, retries) after the lesson started is not sent.
    ],

    'badge_cap' => 9,
];
