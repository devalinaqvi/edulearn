---
paths:
  - 'app/Actions/VideoLectureWorkflow.php'
  - 'app/Services/VideoProbe.php'
  - 'app/Services/PrivateMediaStream.php'
  - 'app/Http/Controllers/VideoLectureController.php'
---

# Video lectures

Accepted media is **MP4 / H.264 / AAC only**, validated by parsing the ISO-BMFF box tree in
`VideoProbe`. **ffprobe and FFmpeg are not installed and are not dependencies.** Never shell out
to them, and never interpolate an uploaded filename into a command. HEVC is rejected on purpose:
Firefox and many Linux browsers cannot play it.

There is no transcoding, no frame-grabbed posters and no speech-to-text. Do not add UI or docs
that imply otherwise. `processing_status` exists so a queued stage can be added later; publication
is blocked unless it is `ready`.

The video size limit (`config/video.php`) is deliberately separate from the 10 MB study-material
limit. **Raising the video limit must never raise the document limit.**

Videos, posters and captions live on the private disk under generated 32-hex filenames and are
only ever served through authorized routes. Learner access requires an active account, a published
course, an enrollment, and a published + ready lecture. Byte delivery goes through
`PrivateMediaStream`, which must keep Range/HEAD/206/416 behaviour and chunked streaming — never
`file_get_contents` a video.

Watched time is the minimum of the client-reported delta, new forward ground, and real elapsed
wall clock times the max playback rate, with the first report additionally capped by a fraction of
the duration. All three bounds are load-bearing: dropping any one lets a seek to the end complete
a lecture.
