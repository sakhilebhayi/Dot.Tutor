<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 code shown next to every rate. Dot.Tutor does not take payment
    | yet (no provider chosen), so this is a label on a stored agreement, not a
    | charge. It is stamped onto a profile when the tutor applies and onto each
    | session when it is booked, so changing it later never rewrites history.
    |
    */

    'currency' => env('TUTOR_CURRENCY', 'ZAR'),

    /*
    |--------------------------------------------------------------------------
    | Default timezone for a new tutor's weekly hours
    |--------------------------------------------------------------------------
    */

    'timezone' => env('TUTOR_TIMEZONE', 'Africa/Johannesburg'),

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    */

    // How many tutor-local days (today included) the tutor page offers.
    'booking_horizon_days' => 14,

    // Bookable start times are offered on this grid, in minutes.
    'slot_step_minutes' => 30,

    // Session lengths a student can choose, in minutes.
    'durations' => [30, 60, 90, 120],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute, per signed-in user)
    |--------------------------------------------------------------------------
    */

    'throttle' => [
        'bookings' => (int) env('TUTOR_THROTTLE_BOOKINGS', 10),
        'uploads' => (int) env('TUTOR_THROTTLE_UPLOADS', 10),
    ],

];
