<?php

/*
 * R-47 community (rooms, Q&A, study groups, tutor rooms). docs/R47_COMMUNITY.md explains each value.
 */
return [
    // Posting limits, enforced in the service (no route can skip them).
    'posts_per_minute' => (int) env('COMMUNITY_POSTS_PER_MINUTE', 5),
    'posts_per_day' => (int) env('COMMUNITY_POSTS_PER_DAY', 60),
    'edits_per_minute' => (int) env('COMMUNITY_EDITS_PER_MINUTE', 10),

    // Distinct people reporting one post before it is held for review (a child-safety report holds at once).
    'hold_after_reports' => (int) env('COMMUNITY_HOLD_AFTER_REPORTS', 3),

    'max_title' => 200,
    'max_body' => 8000,

    'group_size' => ['min' => 2, 'max' => 30],
    'room_capacity' => ['min' => 2, 'max' => 200],
    'groups_per_day' => 5,

    // Default timezone for scheduled sessions (a person may choose another).
    'timezone' => env('COMMUNITY_TIMEZONE', 'Africa/Johannesburg'),

    // Seconds a room page waits between activity checks.
    'poll_seconds' => 30,

    'currency' => 'ZAR',
];
