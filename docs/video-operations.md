# Video lectures — storage, formats and operations

Status: implemented and tested at source level on 24 September 2026. Production acceptance
(load, availability, accessibility conformance) has **not** been measured.

## Supported media

Only **MP4 (ISO base media file format) carrying H.264 (AVC) video and, optionally, AAC audio**
is accepted. Both `.mp4` and `.m4v` extensions are allowed; the extension is never trusted on its
own.

Every upload is validated by `App\Services\VideoProbe`, which parses the container's box tree
(`ftyp`, `moov`, `mvhd`, `trak` → `mdia` → `hdlr`, `minf` → `stbl` → `stsd`) directly in PHP and
reads:

- the major/compatible brand (`isom`, `iso2`, `iso4`, `iso5`, `iso6`, `mp41`, `mp42`, `avc1`,
  `M4V `, `mmp4`, `dash`),
- the first video and audio sample-entry formats (`avc1`/`avc3` → H.264, `mp4a` → AAC),
- the duration, from the movie header's timescale and duration fields.

Rejected with a specific message: non-MP4 data, wrong extension, truncated files, files with no
video track, zero/absent duration, HEVC (`hvc1`/`hev1`) and any non-AAC audio codec.

**HEVC is deliberately rejected.** It has no dependable playback story across Firefox and Linux
browsers, so accepting it would publish lectures some learners cannot watch.

### Why not ffprobe

`ffprobe` is **not installed on this machine** and is not a dependency of this application. The
probe is pure PHP, which also means no subprocess is ever spawned and no uploaded filename is
ever interpolated into a shell command.

### Transcoding is not implemented

There is no FFmpeg pipeline, no format conversion, and no automatic thumbnail extraction.
Instructors must upload browser-playable H.264/AAC MP4 files. The schema carries a
`processing_status` column (`pending` / `ready` / `failed`) and publication is blocked unless it
is `ready`, so a queued transcoding stage can be added later without another migration. Today
validation is synchronous and successful uploads are recorded directly as `ready`.

Poster images are **uploaded**, not generated from a frame (JPEG, PNG or WebP, verified with
`getimagesize`).

Transcripts are **entered or pasted by staff**. There is no speech-to-text. A lecture without a
transcript cannot be used as an AI study-note source, and says so.

## Size limits

| Layer | Setting | Value |
| --- | --- | --- |
| Application | `config/video.php` → `max_kilobytes` (`VIDEO_MAX_KILOBYTES`) | 512000 (500 MB) |
| PHP-FPM (site only) | `upload_max_filesize` / `post_max_size` | 512M / 520M |
| nginx (site only) | `client_max_body_size` | 520M |
| PHP-FPM (site only) | `max_execution_time` / `max_input_time` | 600 |
| nginx (site only) | `fastcgi_read_timeout` / `fastcgi_send_timeout` | 600 |

500 MB was chosen after checking runtime capacity: 148 GB free on the storage volume, and a
500 MB upload is comfortably handled as a single buffered request within a 600 second window.
Uploads stream to PHP's temporary file and are copied to storage with `writeStream`, so peak
memory does not scale with file size.

**The 10 MB study-material limit is unchanged and must stay unchanged.** It is enforced
independently in `UploadMaterialRequest` and `App\Rules\StudyMaterialFile`. Raising the PHP and
nginx ceilings for video does not raise it.

Resumable/chunked upload is **not** implemented. The browser sends one request with progress
reporting, cancellation feedback and a safe retry; a failed upload leaves no lecture record and
no orphaned file.

### Applying the limits (external configuration — still required)

The site-specific limits are already written into `~/.config/valet/Nginx/lms.test`, but **nginx
has not been reloaded**, so the running site still reports `upload_max_filesize=10M`. Apply them
with either:

```
valet restart
# or
sudo nginx -t && sudo systemctl reload nginx
```

Verify afterwards with a temporary `phpinfo()`-style probe, or by uploading a file larger than
10 MB. Global PHP limits for other Valet sites are deliberately untouched.

## Private storage and delivery

Videos, posters and caption files live on the **private** `local` disk under
`storage/app/private/video-lectures/`, with generated 32-hex filenames. The client filename is
retained only as metadata. Nothing is written to the public disk and no permanent public URL
exists.

Every byte is served through authorized routes:

| Route | Serves |
| --- | --- |
| `GET|HEAD /lectures/{lecture}/stream` | the video, with Range support |
| `GET /lectures/{lecture}/poster-image` | the poster |
| `GET /lectures/{lecture}/captions/{track}` | WebVTT captions |
| `GET /lecture-revisions/{revision}/download` | superseded recordings, course staff only |

Authorization for all of them: the actor must pass the course `view` gate, and anyone who is not
course staff additionally needs the lecture to be `published` **and** `ready`. That means an
inactive account, a revoked enrollment, an unpublished course, or a draft/archived lecture all
result in no bytes. Captions are additionally checked to belong to the lecture in the URL.

`App\Services\PrivateMediaStream` implements delivery:

- `Accept-Ranges: bytes` on every response.
- `206 Partial Content` with `Content-Range` for `bytes=a-b`, `bytes=a-` and `bytes=-n`.
- `416 Range Not Satisfiable` with `Content-Range: bytes */<size>` for a start past the end or a
  zero-length suffix.
- Unparseable or multi-range headers are ignored and the whole file is returned (permitted by
  RFC 9110 §14.2).
- `HEAD` returns the headers with no body.
- The body is streamed in 256 KB chunks from a file handle, so PHP memory stays flat regardless
  of file size, and `connection_aborted()` stops work when a learner seeks away.
- `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`.

### Optional: hand byte-pushing to nginx

Set `VIDEO_X_ACCEL_PREFIX` to an `internal` nginx location mapped to the private storage root and
delivery switches to `X-Accel-Redirect`; authorization still runs in PHP first. Leave it empty
(the default) to stream from PHP, which works on any host.

```nginx
location /protected-video/ {
    internal;
    alias /home/devali/Development/applications/AdvanceLearningManagementSystem/storage/app/private/;
}
```
with `VIDEO_X_ACCEL_PREFIX=/protected-video`.

**Do not promise DRM.** Any learner authorized to watch a lecture can capture the delivered
stream. This design controls *access*, not redistribution.

## Progress and completion

Per learner and lecture, `video_lecture_progress` stores the playhead, the furthest point
reached, accumulated watched seconds, and a completion flag. Playback position is saved so a
learner resumes across reloads and devices.

Watched time is the **minimum of three independent bounds**:

1. the delta the player reports;
2. new forward ground — timeline beyond the furthest point previously reached, so replaying the
   same segment never double counts;
3. real elapsed wall-clock time since that learner's previous report, times 2.5 (the 2x maximum
   playback rate plus jitter margin). A learner's first report is capped at 20 seconds.

Bound 3 is what prevents a seek to the end from completing a lecture: one jump reports a large
position but no time has passed, so almost no credit is earned. A lecture is complete at 90% of
its duration (`config/video.completion_ratio`).

Viewing progress is **not** mastery, and is kept separate from the lesson-completion percentage
that drives course progress. Learner records are private; course staff see their own course's
roster.

## Player and accessibility

A native `<video controls preload="metadata" playsinline>` element, which gives keyboard-operable
play/pause, seek, volume and fullscreen for free, plus an explicit playback-speed `<select>`
(0.5x–2x) because browser speed controls are inconsistent. Captions render through `<track>`
elements. There is **no autoplay**. Loading, buffering, processing, unavailable and failure
states are announced through an `aria-live` status region. The player is responsive via a 16:9
`aspect-ratio` frame and stacks its controls below 650 px.

Caption files must be valid WebVTT — the server requires the `WEBVTT` signature, UTF-8, and at
least one timed cue before storing anything, so arbitrary markup can never be served from the
captions route.

## Queue and scheduler

The video module itself does **not** enqueue work today (validation is synchronous). The existing
background processes are still required for AI study notes and quiz finalization:

```
php artisan queue:work database --sleep=1 --tries=3 --timeout=45
php artisan schedule:work
```

Supervision of these on a real host (systemd units or Supervisor) is still **not configured**.

## Tests

`tests/Feature/VideoLectureTest.php` — 13 tests covering upload validation and probe metadata,
rejection of invalid/corrupted/unsupported/oversized media, course-staff isolation, learner
enrollment/publication/archival restrictions, deactivation and revoked enrollment, Range/HEAD/416
delivery, safe replacement with staff-only revision history, WebVTT validation and caption
authorization, transcript-to-notes generation, progress isolation, seek-versus-completion, and
the fact that instructors record no viewing progress.

The suite builds synthetic MP4 files box by box. `VideoProbe` was additionally checked by hand
against five real-world recordings (OBS captures at `iso4` and a screen recording at `mp42`,
34–262 MB), which it read correctly for codec and duration.
