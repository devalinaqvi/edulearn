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
