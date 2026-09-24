# EduLearn requirements reconciliation

Baseline re-verified against code, schema and a full MySQL test run on **24 September 2026**. Status describes source implementation and automated-test evidence, not production acceptance. Runtime evidence is in `validation.md`; video specifics are in `video-operations.md`.

Suite at the time of writing: **89 tests, 951 assertions, all passing** on MySQL (`acumen_university_test`). The pre-existing baseline was 62 tests / 687 assertions plus one failing generated placeholder.

## Architecture and compatibility

The repository is a Laravel 13.31.0 modular application running PHP 8.3.31, using MySQL for persistent application data, Eloquent, Blade, private local storage, database sessions/cache/queues, and PHPUnit 12.5.34. The UI uses existing local CSS and small progressive-enhancement JavaScript. There is no npm package manifest or frontend framework build.

Laravel 13 requires PHP 8.3 or newer; the SRS's PHP 8.2 minimum is incompatible. Keep the installed Laravel/PHP major versions instead of introducing an unrelated upgrade. See [Laravel 13 support policy](https://laravel.com/docs/13.x/releases), [deployment requirements](https://laravel.com/docs/13.x/deployment), and [PHP support windows](https://www.php.net/supported-versions.php). The running MySQL version must be verified during environment validation. SQLite remains only a portable test backend, never delivered application persistence.

The specification both permits retaining the current frontend and requests Bootstrap. Decision: retain the established stack and visual components for milestone one. No Bootstrap, jQuery, Alpine or npm dependency is claimed to be installed. A future build-tool change must have a concrete reason. EduLearn replaces the Acumen display name, while retaining existing account identifiers and the restrained green visual identity.

## Requirements checklist

| Requirement | Status | Evidence or remaining work |
| --- | --- | --- |
| Three primary roles and server authorization | Implemented | Admin/instructor/student storage roles; student is displayed as learner. Ownership/co-instructor/enrollment policy applies to course operations |
| Login/logout and role dashboard redirects | Implemented | LoginRequest, AuthController, role dashboard routes, session regeneration/invalidation |
| Five failed-attempt lockout | Implemented | Hashed normalized email key, five failures in a configurable window; separate lock expires 15 minutes after the fifth failure by default; IP request throttling is additional |
| Password reset | Partial | Laravel broker and automated notification tests exist; real SMTP delivery remains to be configured/verified |
| Administrative account provisioning/update | Implemented | Admin account forms, role validation, duplicate-email validation, account revision checks and safe activity records |
| Deactivation and existing-session revocation | Implemented | Active-account middleware, auth version checks, remember-token rotation, database-session removal; reactivation does not restore old sessions |
| Profile/password updates | Implemented | Current-password validation, restricted fields, unique email, session revocation after password change |
| Public signup | Out of scope | Accounts are administrator-provisioned |
| Learner self-enrollment | Implemented | Latest user request overrides the initial admin-only policy; published courses only, duplicate prevention, audited enrollment and administrator revocation protection |
| Course title/description/status/instructor | Implemented | Existing management screens, primary and additional instructors, archive retains learning records |
| Unique course code | Implemented | Unique database constraint, normalized form validation, preserved-record backfill, code search and course display |
| Administrator-managed course enrollment | Implemented | Audited access screen and unique online enrollment constraint |
| PDF/DOCX/PPTX materials, 10 MB, complete metadata | Implemented and tested | 10 MB private uploads, Office content validation, uploader/size/time metadata, progress feedback. Replacement with optimistic version checks, retained file revisions, staff-only revision downloads, archival that withdraws learner access and blocks new AI generation while preserving existing notes. `MaterialController`, `2026_09_15_070733_add_material_revision_history`, `MaterialLifecycleTest` (4 tests). Historical unknown uploader/size legitimately remain null |
| Assignment authoring and private submissions | Partial | Existing title/instructions/deadline/marks, text/file submissions and private downloads; draft publication, attachments and new strict deadline policy remain |
| Reject late submissions | Implemented | Server rejects at or after the effective UTC deadline; individual approved extensions apply |
| Submission history and replacement policy | Implemented | Ungraded work may be replaced before deadline; retained text/private file revisions, authorized history downloads, duplicate-content idempotency and form version guard |
| Rubrics, marks, feedback and grade corrections | Implemented | Bounded decimal marks, criterion totals, immutable rubric after first submission, grade history and stale-form protection |
| Result draft/publication states | Implemented | `AssessmentWorkflow::publishResult`, `result_publications`, published snapshots, corrections stay draft until republished. `ResultPublicationTest` |
| Configurable grading/rounding policy | Missing | No course grading-policy administration yet |
| Timed MCQ quizzes | Implemented | Draft review, immutable publication, one resumable attempt, saved answers, server deadline and objective scoring |
| Short-answer quizzes | Implemented | Authoring, manual bounded decimal grading, review audit and explicit publication. `ShortAnswerQuizTest`, `QuizWorkflow::review` |
| Visible countdown/configurable attempt limit | Partial | Visible countdown and automatic server finalization request added; server remains authoritative. Configurable attempt limits remain |
| Expired quiz finalization | Partial | Idempotent scheduled command exists; host scheduler supervision must be configured and verified |
| Course announcements | Partial | Scoped course announcements exist; complete author/publication metadata and unread tracking remain |
| Platform announcements and assignment/submission notifications | Missing | Milestone 3 |
| Private AI study notes | Partial | Queue, private library, limits, retries, source hash/version checks, mock/direct OpenAI provider paths exist |
| Document extraction | Partial | Lesson/TXT/Markdown only; no PDF/DOCX/PPTX extraction or OCR claims |
| Note editing/regeneration and provider/model metadata | Partial | Rename/delete exists; content editing, explicit regeneration and complete model metadata remain |
| Instructor AI question generation/review | Missing | Separate milestone after study notes; never publish generated drafts automatically |
| OpenRouter/free models/AI administration | Missing | Live catalog, encrypted credentials, approved spending controls and connection checks required; no paid fallback permitted |
| Learner dashboard | Partial | Enrolled courses/progress, deadlines, quiz windows, materials, course announcements and notes; published-result feed/general announcements await later milestones |
| Instructor dashboard | Partial | Assigned courses, enrollments, pending reviews and assessment windows; richer submission history awaits milestone 3 |
| Admin dashboard/activity | Partial | Database-backed course/enrollment/account counts, management links and recent account activity; fuller reports await milestone 7 |
| Responsive/accessible shared UI | Partial | Focus styles, labels, skip link, pagination, responsive layout and choice controls; WCAG 2.1 AA not certified |
| Performance/availability targets | Missing | No representative 100/200-user load run or availability observation period completed |
| Campus ERP/attendance/degrees/physical timetables | Out of scope | Application modules removed; retained historical schema fixtures are upgrade-test inputs only |
| Payments/certificates/video/forums/mobile/advanced analytics | Out of scope | Separate future backlog, not core milestone deliverables |

## Assumptions and contradictions

- Accounts are administrator-managed. The latest user request enables learner self-enrollment in published courses alongside administrator enrollment. Public registration remains disabled.
- Store timestamps in UTC and label UTC displays. Localized display and timezone-aware maintenance preferences require later work.
- Assignment replacement will be allowed only before the effective deadline, keeping immutable versions. Strict rejection at and after the effective deadline is implemented.
- Quiz initial policy is one attempt. Later configuration must not silently grant more attempts or change an already-started attempt's deadline.
- Result release must be explicit. Existing immediately visible grades are legacy behavior that milestone 4 replaces.
- The 10 MB/10 second upload target at 5 Mbps is infeasible: 10 MB takes roughly 16 seconds before overhead (10 MiB roughly 16.8 seconds). Measure network transfer and server processing separately; proposed acceptance is transfer consistent with available bandwidth plus a separately measured server-processing budget, to be agreed before performance acceptance.
- Proposed peak-load acceptance: at 200 concurrent users, no lost/duplicate assessment writes and p95 responses below five seconds; normal-load p95 below three seconds. These are proposed measurable thresholds, not achieved results.
- Availability target: 99% per calendar month, excluding only preannounced approved maintenance. Maintenance timezone, weekday 02:00–04:00 window and 24-hour notice remain configurable requirements, not implemented scheduling.
- Production deployment and destructive changes require explicit authorization. The prior online-scope request authorized campus removal; its conversion has a separate backed-up retention plan. No Git push/pull/fetch or deployment is authorized.

## Video lectures (new core requirement)

| Requirement | Status | Evidence |
| --- | --- | --- |
| Authoring by administrators and assigned instructors | Implemented and tested | `VideoLectureController`, `VideoLectureWorkflow`, course `manage` gate |
| Course/lesson association, title, description, display order | Implemented and tested | `video_lectures` schema, `updateDetails` |
| Draft / published / archived states with timestamps and actors | Implemented and tested | `changeStatus`; publication blocked unless media is `ready` |
| Upload metadata: uploader, filename, MIME, container, size, duration, processing status | Implemented and tested | Recorded from `VideoProbe` at upload |
| Safe replacement with version retention | Implemented and tested | `video_lecture_revisions`, optimistic `version` check, staff-only revision downloads |
| Optional poster/thumbnail | Implemented | Uploaded and content-verified image; **not** generated from a frame (needs FFmpeg) |
| Captions and transcript management | Implemented and tested | Validated WebVTT, plain-text transcript, `video_lecture_tracks` |
| Documented supported formats | Implemented | MP4 / H.264 / AAC only — `docs/video-operations.md` |
| Media validated with an appropriate tool | Implemented and tested | `VideoProbe` parses the ISO-BMFF box tree in pure PHP. **ffprobe is not installed**; no subprocess is spawned and no filename reaches a shell |
| Transcoding | **Not implemented** | Documented. `processing_status` exists so a queued stage can be added without migration |
| Separate configurable video limit, document limit unchanged | Implemented and tested | `config/video.php` (500 MB default); the 10 MB material limit is untouched and separately asserted |
| Aligned app/PHP/web-server limits | **Blocked on external configuration** | Written into `~/.config/valet/Nginx/lms.test`; nginx has **not** been reloaded (needs sudo), so the live site still enforces 10M |
| Resumable/chunked upload | **Not implemented** | Single request with progress, cancellation and safe retry. Not claimed as supported |
| Private storage, generated filenames, no public URLs | Implemented and tested | Private disk, 32-hex names, authorized routes only |
| Server-authorized playback incl. deactivation and revocation | Implemented and tested | Active account + published course + enrollment + published/ready lecture |
| HTTP Range / HEAD, 206 and 416 | Implemented and tested | `PrivateMediaStream`; verified in tests **and** live over HTTPS on the Valet site |
| No whole-file buffering | Implemented | 256 KB chunked streaming from a file handle |
| Optional internal nginx delivery | Implemented | `VIDEO_X_ACCEL_PREFIX`, off by default |
| Responsive, keyboard-accessible player with speed and captions | Implemented | Native controls + explicit speed select, `aria-live` states, 16:9 frame, no autoplay. **Not** yet verified with assistive technology |
| Playback position saved and resumed | Implemented and tested | `video_lecture_progress` |
| Completion excludes seeking; no double counting | Implemented and tested | Credit = min(client delta, new forward ground, wall-clock elapsed x 2.5), first report additionally capped. A live check found a short-lecture hole, which was fixed and regression-tested |
| Viewing progress distinct from mastery | Implemented | Kept separate from lesson-completion course progress |
| Learner records private; staff see their course | Implemented and tested | Progress roster on the lecture page |
| Transcript as an AI note source | Implemented and tested | `source_type=lecture`, reuses quota/queue/authorization. Automatic transcription is **not** claimed |
| External embeds | Out of scope | Uploaded private playback is the delivered core; no provider allowlist was added |
| DRM | Out of scope, explicitly | Access is controlled; redistribution of delivered bytes is not preventable |

## Document text extraction

| Source | Status |
| --- | --- |
| Lesson text, UTF-8 TXT, Markdown | Implemented and tested |
| DOCX | Implemented and tested — WordprocessingML runs, paragraph order preserved |
| PPTX | Implemented and tested — DrawingML runs, slide order preserved |
| PDF (text-based) | Implemented and tested — raw and Flate streams, `Tj`/`TJ`/`'`/`"` operators, escapes |
| Scanned/image-only PDF | Reported as needing OCR. **No OCR is implemented** |
| Encrypted PDF | Detected and reported |
| Empty/malformed documents, oversized extraction | Detected and reported |

Evidence: `App\Services\DocumentText`, `DocumentExtractionTest` (9 tests).

## Milestone plan

1. **Accounts and dashboard foundation:** provision/update/deactivate, lockout, profiles/passwords, role redirects, access revocation, useful database-backed dashboard sections. Implemented in source; see validation results.
2. **Courses and materials:** unique codes, assignment control, administrator and learner enrollment, 10 MB PDF/DOCX/PPTX validation and metadata, safe material lifecycle.
3. **Assignments and communication:** publication/attachments, hard deadlines, retained submission versions, feedback, dashboard notifications, platform/course announcement audiences.
4. **Quizzes and released results:** short answers/manual review, countdown/recovery, configurable attempts, grading policy and draft/published results.
5. **AI study notes:** supported document extraction, structured source-grounded notes, edit/regenerate, full provider/model metadata and failure handling.
6. **Instructor AI and administration:** draft question review/acceptance, OpenRouter model discovery, encrypted credentials, quotas, connection testing, failure/spend visibility.
7. **Acceptance verification:** reports, responsive/keyboard/accessibility review, security regression, representative MySQL load tests, queue/scheduler supervision and operating runbooks.

## Current execution gate

Milestone one is implemented and tested on isolated databases. Following explicit user approval, the scope conversion and account migrations were applied to the local MySQL database. Valet browser smoke checks passed at mobile, tablet and desktop widths. Real email delivery and full accessibility acceptance remain unverified.


## Still outstanding (as of 24 September 2026)

Not started or incomplete, stated plainly so the checklist is not read as completion:

| Item | Status |
| --- | --- |
| Assignment draft/published workflow and instructor attachments | **Missing** |
| Assignment/submission dashboard notifications | **Missing** |
| Configurable quiz attempt limits | **Missing** — policy remains exactly one attempt |
| Configurable grading and rounding policy | **Missing** |
| Published-result dashboard summaries and role-scoped reports | **Missing** |
| Instructor AI quiz-question generation | **Missing** |
| Queue/scheduler supervision on a real host (systemd/Supervisor) | **Not configured** |
| SMTP delivery verification | **Not verified** — mailer is `log` locally |
| nginx reload to apply the video upload limits | **Blocked** — needs sudo |
| Accessibility conformance (WCAG 2.1 AA) | **Not verified** — no assistive-technology or visual responsive testing was performed |
| Performance and availability targets | **Not measured** — no load test was run |
| Real external AI inference | **Not proven** — provider paths are covered only by mocked HTTP and an auth-only connection check |
