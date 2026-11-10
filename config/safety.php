<?php

use App\Domain\Safety\Models\Message;
use App\Models\LessonResource;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Safeguarding (R-47 safety domain, app/Domain/Safety)
|--------------------------------------------------------------------------
|
| Everything here is a policy setting the owner or a safeguarding lead may
| need to change without a code change. Nothing in this file is a legal
| compliance claim: see docs/R47_SAFETY.md and docs/R47_RESEARCH.md.
|
*/

return [

    // Age bands: a child is under `teen_from`, a teen is under `adult_from`.
    'ages' => [
        'teen_from' => 13,
        'adult_from' => 18,
    ],

    // How a person with no known date of birth is treated. 'adult' keeps today's flows working until the
    // academic onboarding collects a date of birth from every learner; 'restrict' treats unknown as a child
    // (the safest setting, and the one to switch to once dates of birth are collected). See D-402.
    'unknown_age_policy' => env('SAFETY_UNKNOWN_AGE_POLICY', 'adult'),

    'guardians' => [
        // Hours a guardian invitation link stays valid.
        'invitation_ttl_hours' => 72,
        // The signed-in guardian's email must equal the invited address (case-insensitive).
        'require_matching_email' => true,
        // Version of the consent text the guardian accepts. Bump when docs/R47_SAFETY.md section 4 changes.
        'consent_version' => '2026-11-10.1',
        // Invitations a learner may send per day.
        'invitations_per_day' => 5,
    ],

    // Which actions wait for a guardian's approval, per age band. A child's are fixed (always); a teen's are
    // defaults a linked guardian may change on their dashboard.
    'approvals' => [
        'kinds' => ['booking', 'purchase', 'paid_room', 'live_lesson'],
        'child' => ['booking' => true, 'purchase' => true, 'paid_room' => true, 'live_lesson' => true],
        'teen' => ['booking' => true, 'purchase' => true, 'paid_room' => true, 'live_lesson' => false],
        // Hours a request waits before it lapses (the learner may ask again).
        'expiry_hours' => 72,
    ],

    'messaging' => [
        // Minor age bands that may only talk to adults inside a booking, room or support conversation.
        'context_required_for' => ['child', 'teen'],
        // Messages per user per minute, and new conversations per user per hour.
        'per_minute' => (int) env('SAFETY_MESSAGES_PER_MINUTE', 20),
        'new_conversations_per_hour' => (int) env('SAFETY_CONVERSATIONS_PER_HOUR', 10),
        'max_length' => 4000,
        // Attachment size cap in kilobytes, and the accepted types (sniffed, not trusted from the name).
        'attachment_max_kb' => 5120,
        'attachment_mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'],
        'disk' => 'safety',
    ],

    'reports' => [
        // Reports one person may file per hour.
        'per_hour' => 20,
        // Hours to first response by priority (the moderation queue sorts by this).
        'response_hours' => ['critical' => 1, 'high' => 4, 'normal' => 24, 'low' => 72],
        // Report types the HTTP endpoint accepts: alias => model class. Classes that do not exist yet
        // (wave-2 domains) are skipped. Other domains report in code through the ReportSink seam.
        'reportable' => [
            'message' => Message::class,
            'profile' => User::class,
            'tutor_profile' => TutorProfile::class,
            'review' => SessionRating::class,
            'resource' => LessonResource::class,
        ],
    ],

    'incidents' => [
        // Minutes to acknowledge before the incident escalates one level, by severity.
        'respond_within_minutes' => ['critical' => 60, 'high' => 240, 'medium' => 1440],
        // After this many escalations the incident stays at the top level and keeps notifying.
        'max_level' => 3,
    ],

    'screening' => [
        // Check kinds that must all be cleared (and unexpired) before a tutor may work with minors. Each is
        // recorded by an operator with evidence; nothing here is checked automatically. See D-411.
        'required_kinds' => ['child_protection_register', 'sex_offenders_register'],
        // A different operator must review the record before it counts.
        'four_eyes' => (bool) env('SAFETY_SCREENING_FOUR_EYES', true),
        'evidence_max_kb' => 10240,
    ],

];
