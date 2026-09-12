# EduLearn requirements reconciliation

Baseline inspected: 12 September 2026. Status describes source implementation, not production acceptance. Test and runtime evidence is recorded separately in `validation.md`.

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
| Unique course code | Missing | Milestone 2 migration/backfill required |
| Administrator-managed course enrollment | Implemented | Audited access screen and unique online enrollment constraint |
| PDF/DOCX/PPTX materials, 10 MB, complete metadata | Partial | Current PDF/TXT/Markdown storage/download is limited to 5 MB; uploader/size metadata, Office file validation and safe replacement/removal remain |
| Assignment authoring and private submissions | Partial | Existing title/instructions/deadline/marks, text/file submissions and private downloads; draft publication, attachments and new strict deadline policy remain |
| Reject late submissions | Missing | Legacy workflow currently accepts/labels late work; must change in milestone 3 |
| Submission history and replacement policy | Partial | One current submission and version guard exist; file/text replacement history must be added before claiming full evidence retention |
| Rubrics, marks, feedback and grade corrections | Implemented | Bounded decimal marks, criterion totals, immutable rubric after first submission, grade history and stale-form protection |
| Result draft/publication states | Missing | Legacy grading exposes marks immediately; publication/confirmation and correction-release workflow are milestone 4 |
| Configurable grading/rounding policy | Missing | No course grading-policy administration yet |
| Timed MCQ quizzes | Implemented | Draft review, immutable publication, one resumable attempt, saved answers, server deadline and objective scoring |
| Short-answer quizzes | Missing | Manual review and result publication needed |
| Visible countdown/configurable attempt limit | Partial | Server times and deadline text exist; countdown and policy-configurable limits are not implemented |
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
- Assignment replacement will be allowed only before the effective deadline, keeping immutable versions. The current late-acceptance behavior is explicitly not SRS-compliant yet.
- Quiz initial policy is one attempt. Later configuration must not silently grant more attempts or change an already-started attempt's deadline.
- Result release must be explicit. Existing immediately visible grades are legacy behavior that milestone 4 replaces.
- The 10 MB/10 second upload target at 5 Mbps is infeasible: 10 MB takes roughly 16 seconds before overhead (10 MiB roughly 16.8 seconds). Measure network transfer and server processing separately; proposed acceptance is transfer consistent with available bandwidth plus a separately measured server-processing budget, to be agreed before performance acceptance.
- Proposed peak-load acceptance: at 200 concurrent users, no lost/duplicate assessment writes and p95 responses below five seconds; normal-load p95 below three seconds. These are proposed measurable thresholds, not achieved results.
- Availability target: 99% per calendar month, excluding only preannounced approved maintenance. Maintenance timezone, weekday 02:00–04:00 window and 24-hour notice remain configurable requirements, not implemented scheduling.
- Production deployment and destructive changes require explicit authorization. The prior online-scope request authorized campus removal; its conversion has a separate backed-up retention plan. No Git push/pull/fetch or deployment is authorized.

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
