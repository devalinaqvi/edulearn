# EduLearn — architecture and operating reference

Written after the production-readiness work described below. It records what the system does
today, not what is planned. Diagrams live in `docs/diagrams/`; the requirement-by-requirement
status stays in `docs/requirements-status.md`.

---

## 1. Existing architecture

A server-rendered Laravel 13 application on PHP 8.3 with MySQL. Blade renders every page. There
is no npm manifest, no bundler and no frontend framework: the interface is one hand-written
stylesheet and one progressive-enhancement script, both served from `public/`. A strict
Content-Security-Policy (`script-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`)
means there are no inline scripts, styles or event handlers anywhere in the views.

Layering is deliberately shallow:

- **Routes** — a single `routes/web.php`, one guest group and one authenticated group.
- **Controllers** — thin. They authorize, delegate, and choose a response.
- **Actions** — every multi-step mutation. This is where transactions, the shared write lock,
  re-authorization and optimistic-concurrency checks live.
- **Services** — single-purpose collaborators with no knowledge of HTTP.
- **Models** — Eloquent where a record has behaviour; several tables (quizzes, attempts, audit
  trails) are driven through the query builder and have no model by design.

## 2. Improved architecture

What this work added, and why:

| Added | Reason |
| --- | --- |
| `Actions\WriteLock` | The lock was inlined in 19 places and failed open when its row was missing |
| `Services\DisplayTime` | One boundary between stored UTC and displayed local time |
| `Services\DependencyAnalyzer` | Deletion safety decided from the real foreign-key graph |
| `Actions\ContentLifecycle` | Archival, restoration and guarded permanent deletion |
| `Services\RichText` | The only route by which authored HTML enters the system |
| `Services\QuizQuestionProvider` | AI-drafted questions over the existing provider configuration |
| `Actions\AssignmentMediaLibrary` | Reference media validated by content, not by filename |
| `Console\EraseApplicationData` | A data reset that keeps the schema and the migration history |
| `Services\CourseExport` | A copy of a course's content, so deletion can be taken back |
| `Actions\AccountErasure` | Article 17 erasure that severs the person without destroying the record |

## 3. Database relationships

See `docs/diagrams/erd.md`, generated from the live schema. The shape in one sentence: a course
owns its content, a learner's relationship to a course is an enrolment, and everything a learner
produces hangs off one of those two.

## 4. Deletion strategy

Four independent defences, in order:

1. **Authorization** — re-checked against freshly loaded state inside the transaction, never
   trusting the state that was read when the page was rendered.
2. **Serialization** — the shared write lock, so two staff cannot race the same decision.
3. **Dependency analysis** — `DependencyAnalyzer` reads foreign keys from `information_schema`
   and counts referencing rows. It is not a hand-maintained list, so a table added by a future
   migration is accounted for the day it appears.
4. **Database constraints** — `RESTRICT` refuses the delete even if everything above were wrong.

Constraints are never disabled to make a delete succeed.

## 5. Soft-delete strategy

There is no `deleted_at` column and no `SoftDeletes` trait. Removal is expressed as a `status`
on the record — the idiom courses, materials and video lectures already used, now extended to
lessons. Two parallel notions of "removed" would force every query, policy and relation to decide
which one it meant.

An archived lesson leaves the learner's view, stops counting toward course progress and refuses
completion, while every `lesson_completions` row already earned is retained.

## 6. Hard-delete safety rules

Permanent deletion is offered only where the analyser reports no blocking reference, and only
from Trash, where the volume is small enough to compute a report per record.

- A lesson is blocked by completions, study notes or materials.
- A course is blocked by lessons, enrolments, assessments, announcements, notes or lectures.
- When blocked, the record is **archived instead** and the response names what is being
  preserved — "…because 3 learner progress records depend on this record" — rather than showing
  a constraint violation.
- Course deletion additionally requires an administrator.

## 7. Authorization model

`CoursePolicy` carries the whole model:

| Ability | Granted to |
| --- | --- |
| `manage` | Active admins; active instructors who own the course or co-teach it |
| `view` | Anyone who can `manage`, plus enrolled learners |
| `participate` | Active learners enrolled in a **published** course |
| `enroll` | Active learners, published course, not previously removed by an administrator |
| `studySource` | Same as `participate` |

Publication is a **visibility** state and never withdraws authoring rights. Course status appears
nowhere in `manage`.

## 8. Quiz lifecycle

See `docs/diagrams/quiz-lifecycle.md`. The rules worth stating in prose:

- The availability window is optional at both ends. Absence is a null column, never a sentinel
  date, so "no deadline" cannot become a deadline when some far-future date arrives.
- Publishing a quiz freezes its question set, so an attempt is always scored against the paper it
  was sat under. Title, instructions, window and duration stay editable.
- An attempt's `deadline_at` is fixed when it starts; moving the window never changes time a
  learner was already promised.
- Results are released only after the quiz closes — or, when nothing closes, once the learner's
  own attempt is finalized.

## 9. Timezone handling

Storage is UTC everywhere; `config('app.timezone')` is UTC and every column holds UTC.
Interpretation happens at two edges only, both in `DisplayTime`:

- **Input** — a value typed into a form is read in `lms.display_timezone` (default
  `Asia/Karachi`) and converted to UTC **before validation runs**. This ordering is the whole
  point: `after:now` and `after:opens_at` resolve in the application timezone, so a local
  wall-clock string compared against a UTC instant would reject any window opening within the
  next five hours.
- **Display** — `@showtime`, `@showdate`, `@inputtime` and `@tz` convert back and name the zone.

No fixed offset is ever added or subtracted; conversion goes through the timezone database.

## 10. Announcement scheduling

An announcement is a draft (`published_at` null), scheduled (future) or published (past).
Visibility is a **query condition**, not a background job: it becomes visible because its time has
passed, so a stopped worker can neither hold one back nor release one early. Unpublished
announcements can be edited or discarded by their author; published ones cannot — a correction is
published instead of silently rewriting what people already read.

## 11. Media upload workflow

Three separate upload paths, each validated by **content** rather than by the filename or the
browser-supplied MIME type:

| Path | Accepted | Validated by | Ceiling |
| --- | --- | --- | --- |
| Study materials | PDF, DOCX, PPTX, TXT, MD | `Rules\StudyMaterialFile` (zip entry audit, OOXML namespace, no macros) | 10 MB |
| Video lectures | MP4 / H.264 / AAC | `Services\VideoProbe` (ISO-BMFF box tree, in PHP) | `video.max_kilobytes`, 500 MB |
| Assignment reference media | JPEG, PNG, WebP, MP4 | `getimagesize` / `VideoProbe` | `lms.assignment_media.*` |

The three ceilings are independent on purpose: raising one must never raise another. Stored names
are 32-hex generated, so a crafted client filename cannot influence a path. Nothing is served from
a public URL; delivery goes through an authorized route, and video through `PrivateMediaStream`
so Range requests work without buffering the file. No subprocess is ever spawned and no uploaded
filename ever reaches a shell.

## 12. AI quiz workflow

`QuizQuestionProvider` reuses the provider configuration, encrypted credential, zero-retention
settings and failure classification that already serve study notes.

- Generated questions are appended to a **draft** quiz as ordinary draft questions. A published
  quiz refuses them outright.
- Malformed, duplicated or unanswerable items are discarded rather than guessed at: four distinct
  options and an in-range answer, or the question is dropped.
- Provider failures are translated into guidance for an administrator. Response bodies are never
  surfaced, because they can carry account detail.
- Course material is framed to the model as untrusted data, never as instructions.
- Nothing is published automatically. A person reviews and publishes.

## 13. Error-handling strategy

User-facing messages are written for the person; technical detail stays server-side.

- One notification mechanism: flash messages render server-side into a toast region with two live
  regions split by urgency. They are readable before any script runs and survive its absence; the
  script only adds dismissal and timing.
- Standing guidance stays as inline `.notice` content, because it belongs in the page rather than
  in something that disappears.
- Error views render only application-authored `abort()` messages, never exception internals.
- Deletion refusals explain what is being preserved instead of surfacing a constraint violation.

## 14. Empty database behaviour

An installation holding only login accounts is a supported state. `EmptyDatabaseTest` exercises
13 screens across all three roles plus login, logout and an empty search. No crash was found, so
no null-safety churn was introduced: the layout's site-name lookup already falls back to config,
every list uses `@forelse`, and `AiSettings::current()` falls back when no row exists.

Two rows are genuinely required and are recreated by both the erase command and the minimal seed:
`lms_write_locks` row 1, and the `site_name` setting.

## 15. Seeding strategy

| Command | Purpose |
| --- | --- |
| `php artisan lms:erase` | Delete application data, keep schema and migration history |
| `php artisan db:seed --class=MinimalLoginSeeder` | Three role accounts, no content |
| `php artisan db:seed` | Complete development dataset |

The complete seed deliberately covers states the happy path never reaches: draft, archived and
content-free courses; an archived course that retains its enrolment; an archived lesson; a graded
and published submission; a finalized quiz attempt; a quiz with no window; and live, scheduled and
draft announcements.

## 16. Testing strategy

PHPUnit against an isolated MySQL database whose name must end in `_test`; `tests/TestCase.php`
refuses anything else before `RefreshDatabase` can drop a table. There is no SQLite run.

Tests assert behaviour at the HTTP boundary wherever possible, because the property that matters
is "this cannot be stored" rather than "this function returns a clean string". Security
properties are asserted negatively — a disguised executable, a `javascript:` URL, a stale form, a
learner reaching another course — so a regression fails loudly.

## 17. Major improvements made

Foundations: a fail-loud shared write lock; remember-me fixed; scheduled announcements no longer
publish immediately; documentation drift corrected.

Correctness: published-course content is editable with integrity rules on what submissions make
immutable; a timezone layer with conversion ordered before validation; optional quiz windows.

Integrity: Trash and restore over the archive idiom, with permanent deletion gated by the real
foreign-key graph; a data-only erase command.

Content and UX: a sanitized rich-text editor; assignment reference media; one toast mechanism;
optional reason fields where they are not audit evidence.

AI: draft quiz generation over the existing provider abstraction, review required before
publication.

## 19. Retention, export and erasure

Three obligations that pull against each other, resolved separately:

**Export.** `GET /courses/{course}/export` produces a zip of a course's teaching content so that
deletion is a decision that can be taken back. It deliberately carries no learner data: an export
is a file that gets emailed and forgotten, and is not a lawful basis for moving somebody's
assessment record onto a laptop.

**Retention.** `lms:purge-trash` permanently removes archived content older than
`lms.trash_retention_days` (90 by default), scheduled daily. The window never relaxes the
deletion rules: every candidate still passes through `DependencyAnalyzer`, so anything carrying
learner history is retained however old it is. Purges are recorded with a null actor, which reads
as "System", because attributing an automatic cleanup to a person would be a fiction.

**Erasure.** `AccountErasure` honours a GDPR Article 17 request by severing the person from the
record rather than destroying it. Identifying columns are overwritten in place; study notes,
announcement read receipts, viewing positions and sessions are deleted; enrolments, submissions,
attempts, marks and their audit trails are retained under the Article 17(3) exemptions for legal
obligations and legal claims. The overwrite is one-way: keeping any means of reversing it would
make this pseudonymisation, which is still personal data and would not discharge the request.
Administrator only, never on oneself, never on the last active administrator, and confirmed by
typing the account's email address.

## 20. Remaining technical debt

- **Not verified**, as distinct from not built: real SMTP delivery, live external AI inference,
  WCAG 2.1 AA conformance, and load or availability targets. No browser or assistive technology
  was driven during this work.
- Erasure covers the application database only. Database backups, queue payloads and server logs
  may still hold identifying data until they age out, which a retention policy outside this
  application has to address.
- The retention purge covers archived lessons. Archived materials, lectures and courses are not
  swept.
- `Course::progressFor()` still issues two queries per call and is called once per course card.
- Several screens run model queries directly in Blade, and the layout reads `settings` on every
  render.
- Assignment reference media cascades from `assignments`; assignments have no delete path today,
  so this is latent rather than active.
- `league/commonmark` was updated to 2.10.3 during this work to clear two advisories; dependency
  auditing is not otherwise automated.
- Quiz attempt limits and a configurable grading or rounding policy remain unbuilt, as recorded
  in `docs/requirements-status.md`.
