<?php

return [
    /*
    | Maximum accepted upload size for a video lecture, in kilobytes. This is deliberately
    | separate from the 10 MB study-material limit, which must not be raised. The effective
    | limit is also bounded by PHP (upload_max_filesize/post_max_size) and by the web server
    | (client_max_body_size); see docs/video-operations.md.
    */
    'max_kilobytes' => (int) env('VIDEO_MAX_KILOBYTES', 512000),

    /*
    | Poster/thumbnail image limit, in kilobytes. Posters are uploaded, not generated:
    | frame extraction would require FFmpeg, which this application does not depend on.
    */
    'poster_max_kilobytes' => (int) env('VIDEO_POSTER_MAX_KILOBYTES', 2048),

    /* Caption (WebVTT) and transcript limits. */
    'caption_max_kilobytes' => (int) env('VIDEO_CAPTION_MAX_KILOBYTES', 1024),
    'transcript_max_chars' => (int) env('VIDEO_TRANSCRIPT_MAX_CHARS', 200000),

    /*
    | Share of a lecture's duration a learner must actually watch before it counts as
    | complete. Watched time is accumulated from bounded forward playback only, so seeking
    | to the end does not mark a lecture complete.
    */
    'completion_ratio' => 0.9,

    /*
    | Largest chunk written to the client in one pass when streaming a Range response.
    | Keeps peak PHP memory flat regardless of file size.
    */
    'stream_chunk_bytes' => 262144,

    /*
    | Optional internal-redirect delivery. When an X-Accel-Redirect prefix is configured and
    | mapped to an `internal` nginx location pointing at the private storage root, byte
    | delivery is handed to nginx instead of PHP. Leave empty to stream from PHP.
    */
    'x_accel_prefix' => env('VIDEO_X_ACCEL_PREFIX', ''),
];
