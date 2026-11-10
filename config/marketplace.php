<?php

/*
|--------------------------------------------------------------------------
| Dot.Tutor marketplace (R-47, marketplace agent)
|--------------------------------------------------------------------------
|
| Offerings, booking v2, matching and reviews. Matching weights are documented
| in docs/R47_MATCHING.md; change them there and here together.
|
*/

return [

    // Offering durations (minutes): a 15-minute consultation up to a 4-hour exam workshop.
    'min_duration_minutes' => 15,
    'max_duration_minutes' => 240,

    // Group lessons.
    'group_capacity_max' => 30,

    // Packages and recurring series.
    'package_lessons_max' => 40,
    'recurring_weeks_max' => 26,

    // A group seat waiting on payment holds its place this long, then is released.
    'payment_hold_minutes' => 30,

    // An accepted one-to-one booking waiting on payment is held this long (never past its start), then cancelled.
    'payment_window_hours' => 48,

    // Completed lessons can be disputed for this many days.
    'dispute_window_days' => 7,

    // Uploads (kilobytes).
    'photo_max_kb' => 5120,
    'evidence_max_kb' => 8192,
    'photo_max_px' => 800,

    'throttle' => [
        'matching' => (int) env('TUTOR_THROTTLE_MATCHING', 30),
        'reviews' => (int) env('TUTOR_THROTTLE_REVIEWS', 10),
    ],

    'matching' => [
        // Term weights (sum 100). See docs/R47_MATCHING.md.
        'weights' => [
            'scope' => 25,
            'curriculum' => 10,
            'language' => 15,
            'availability' => 15,
            'budget' => 10,
            'help_type' => 5,
            'verification' => 8,
            'reviews' => 7,
            'new_tutor' => 5,
        ],
        // Reviews count toward the score only from this many published reviews.
        'min_reviews' => 3,
        // Reviews reach full confidence at this many.
        'full_confidence_reviews' => 10,
        // A tutor is "new" for this many days after approval, with fewer than min_reviews reviews.
        'new_tutor_days' => 90,
        // Over-budget tolerance: up to this share over budget scores partially, beyond it the tutor is excluded.
        'budget_tolerance' => 0.2,
        // Open slots in the learner's window that count as full availability.
        'availability_full_slots' => 6,
        // Days of slots considered when the learner gives no dates.
        'availability_days' => 14,
        'max_results' => 20,
        // Hard cap on candidates scored per request (after the SQL filters).
        'max_candidates' => 300,
    ],
];
