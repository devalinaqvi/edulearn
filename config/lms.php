<?php

return [
    /*
    | Timezone staff type and read times in. Storage stays UTC; this governs interpretation at
    | the form and display edges only. See App\Services\DisplayTime.
    */
    'display_timezone' => env('LMS_DISPLAY_TIMEZONE', 'Asia/Karachi'),

    /*
    | Reference images and videos attached to an assignment brief. These ceilings are deliberately
    | separate from both the 10 MB study-material limit and the video-lecture limit: a brief's
    | illustration is not a lecture recording, and raising one must never raise another.
    */
    'assignment_media' => [
        'image_max_kilobytes' => (int) env('ASSIGNMENT_IMAGE_MAX_KILOBYTES', 4096),
        'video_max_kilobytes' => (int) env('ASSIGNMENT_VIDEO_MAX_KILOBYTES', 102400),
        'max_per_assignment' => 10,
    ],

    'login_max_failures' => 5,
    'login_lockout_minutes' => (int) env('LMS_LOGIN_LOCKOUT_MINUTES', 15),
];
