<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private storage
    |--------------------------------------------------------------------------
    |
    | Course media, course materials and library files live on this private disk (registered by
    | TutorLearningServiceProvider). Nothing here has a public URL: an authorising controller streams every byte.
    | There is no virus scanner on the shared host; uploads are type-sniffed, size-capped and images re-encoded.
    |
    */

    'disk_root' => env('LEARNING_DISK_ROOT', storage_path('app/private/learning')),

    'max_upload_kb' => (int) env('LEARNING_MAX_UPLOAD_KB', 20480),

    // Uploaded lesson video. The host's PHP upload_max_filesize and post_max_size must be at least this large.
    'max_video_kb' => (int) env('LEARNING_MAX_VIDEO_KB', 204800),

    // Lifetime of a signed media URL (video, slides, images) in minutes.
    'signed_url_minutes' => (int) env('LEARNING_SIGNED_URL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | External video hosts
    |--------------------------------------------------------------------------
    |
    | A lesson may point at an external video only on these hosts (https only). External videos are not protected by
    | Dot.Tutor's access control; the course builder says so to the creator.
    |
    */

    'video_hosts' => ['www.youtube.com', 'youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'vimeo.com', 'player.vimeo.com'],

    /*
    |--------------------------------------------------------------------------
    | Mastery and insights
    |--------------------------------------------------------------------------
    */

    'mastery' => [
        // Most recent marked answers per topic that make up the "recent accuracy" window.
        'window' => 10,
        // Fewer answers than this in a topic and no level is claimed ("not enough evidence yet").
        'min_evidence' => 3,
        'secure_percent' => 80,
        'developing_percent' => 50,
        // Percentage-point change between the earlier and the later half of the evidence that counts as a trend.
        'trend_points' => 15,
    ],

    'throttle' => [
        'uploads' => (int) env('LEARNING_THROTTLE_UPLOADS', 20),
        'attempts' => (int) env('LEARNING_THROTTLE_ATTEMPTS', 30),
        'reports' => (int) env('LEARNING_THROTTLE_REPORTS', 10),
    ],
];
