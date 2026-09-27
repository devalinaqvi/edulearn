# Validation record — EduLearn

The scope refactor passed 40 portable tests (354 assertions). MySQL initially passed 39/40; the remaining failure was a JSON-column comparison in a quiz test, corrected to compare decoded contents. Later evidence supersedes these baseline counts.

Milestone one adds account provisioning, configurable lockout, profile/password updates, active-session enforcement and dashboard isolation coverage. Final run results are recorded after completion.

The source and existing database were backed up before the online scope conversion. Main application migrations and browser checks must be reported separately from isolated test results. Do not infer runtime deployment or production acceptance from tests.

Not yet measured: representative 100/200-user load, p95 latency, 500 ms query targets, monthly availability, complete WCAG 2.1 AA audit and a representative first-assignment usability session.

## Milestone one results

- Full portable PHPUnit run: **46 passed, 446 assertions**, 21.9 seconds. Output: `/tmp/edulearn-m1-tests.log`.
- Full isolated MySQL run: **46 passed, 446 assertions**, 37.6 seconds. Output: `/tmp/edulearn-m1-mysql-tests.log`.
- Pint completed successfully; Composer manifest validation passed. The project has no standalone static-analysis tool configured and no npm build manifest.
- Automated HTTP tests render the authentication, admin, profile, role dashboards, course, quiz and assignment screens. These do not establish visual or WCAG acceptance.
- After explicit user approval, the assessment, online conversion and account migrations completed on the live local MySQL 8.0.46 database. Retained records include 9 users, 6 courses, 12 lessons, 3 materials and 5 study notes. The assessment demo seeder added its practice workflow.
- Valet browser smoke checks passed: all three role logins and dashboard redirects, profile/catalog/admin screens, 33 viewport checks across 320–1920 px with no horizontal overflow or page JavaScript errors. Keyboard Tab reaches the skip link. This is not a complete WCAG audit. Evidence: `/tmp/edulearn-browser-results.json`. No real password-reset email or external AI request was used.

## Applied migration and retention

`database/migrations/2026_09_12_000001_migrate_to_online_lms.php` maps eligible registrations to online enrollments, retains active course teaching access, normalizes only exact old demonstration labels, and drops obsolete ERP tables in foreign-key order. User accounts, courses, lessons, materials, assignments, submissions, grades, progress, and notes are retained. It is forward-only.

`database/migrations/2026_09_12_131559_add_account_controls_to_users.php` adds active-account state, session/account versions, last-login timestamps, and safe account activity records. It removes the old public-registration setting.

Before the refactor, protected backups were created at `/tmp/lms-before-online-refactor-20260912.tar.gz` and `/tmp/lms-before-online-refactor-20260912.sql`. The SQL dump completed with 194,128 bytes. These local backup paths are evidence for this run, not portable installation requirements. They have not undergone a restore drill.


## Role and fresh-install regression audit — 28 September 2026

Executed on Linux with PHP 8.3.31 and the isolated MySQL database `acumen_university_test`. No application database migrations or seed operations were run during this audit.

- Full PHPUnit suite: **110 tests, 1,204 assertions, all passed** (80.964 seconds).
- Frontend regression suite: **4 tests passed** with `node --test --test-reporter=spec tests/upload-ui.test.cjs`.
- `composer validate --no-check-publish`, `composer check-platform-reqs`, Pint, `git diff --check`, and `php artisan view:cache` passed. Application-specific `pdo_mysql`, `zip`, `dom`, `fileinfo`, and `openssl` extensions are available on the tested CLI runtime.

| Area | Automated coverage |
| --- | --- |
| Accounts and roles | Login/logout, lockout, password reset broker, deactivation, profile restrictions, role dashboards, admin account controls |
| Fresh seed data | Admin, instructor and learner logins; authorized dashboard, profile, course, material, assignment, quiz, lecture-list and notes screens; restricted administration pages |
| Courses and materials | Enrollment, instructor isolation, archival, unique codes, private uploads/downloads, document validation/extraction, retained revisions |
| Assignments and results | Deadlines, replacements, extensions, grading, confirmation/publication, unpublished and cross-learner privacy; seeded learner-to-instructor-to-learner workflow |
| Quizzes | Windows, timer enforcement, answer persistence, finalization, objective scores, short-answer review and publication |
| Communication and notes | Audience filtering, read state, ownership, quotas, queue processing, failed generation and authorization rechecks |
| AI administration | Provider catalogues, secret protection, migrated credential recovery, first-time disabled credential provisioning, enablement validation and outage-safe disablement; external inference mocked |
| Video lectures | MP4 metadata validation, upload errors, scoped access, Range/HEAD/416 delivery, captions, poster uploads, retained recordings, transcript notes and bounded progress |
| Installation safety | MySQL defaults, key generation for an empty installation, key preservation on repeat setup, refusal to replace a populated installation's missing key, rejection of unsafe test databases before any connection/schema reset |

Fixes introduced by this audit:

1. `composer setup` and create-project hooks now use `lms:prepare`, preserving existing keys and removing SQLite scaffolding.
2. Test database protection now runs from `createApplication`, before `RefreshDatabase`; it requires an isolated MySQL database ending in `_test`.
3. Disabled AI configurations can save a credential before a model is available; administrators can disable AI without a working provider catalogue.
4. Development materials now include uploader/size/upload-time metadata and announcements identify their author.

Limits: the fresh-install tests exercise new migrations, seed data and setup commands; they are not a clean Windows OS installation. No browser executable is available in this environment, so no new visual, keyboard, native video-decoding or browser accessibility acceptance test was completed. Video fixtures validate the server workflow, not real decoder compatibility. Live AI inference, real SMTP delivery, load targets and availability were not verified. Existing missing SRS modules remain recorded in `docs/requirements-status.md`; passing tests do not mean those modules have been implemented.
