# EduLearn

A fully online LMS under active implementation with Laravel 13.31, PHP 8.3, MySQL, Blade and private file storage. The source contains course learning, assignments, timed multiple-choice quizzes and private study notes, with administrator-managed accounts and learner self-enrollment. No active university ERP workflow is part of the product.

See the [requirements checklist and milestone plan](docs/requirements-status.md), [scope conversion audit](docs/online-lms-audit.md), and [validation record](docs/validation.md). These distinguish implemented behavior from incomplete SRS requirements. This is not a production-readiness claim.

## Local setup

Use the existing Valet site at **https://lms.test**. Do not run `php artisan serve`. For a new installation, copy `.env.example` to `.env`, configure MySQL and run:

```sh
composer install
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
```

Preserve an existing `.env`, application key, database and private storage. The previous scope-conversion migration removes obsolete tables and is forward-only: back up the database and matching source first and follow the retention plan in the audit. Do not use `migrate:fresh` against the application database.

The current frontend uses local CSS/JavaScript and Blade. No npm manifest or asset compilation dependency is required; the SRS permits retaining this stack. Run `php artisan view:cache` for Blade compilation.

## Development accounts

| Role | Email | Initial development password |
| --- | --- | --- |
| Administrator | admin@acumen.test | Learning-demo-2026! |
| Instructor | instructor@acumen.test | Learning-demo-2026! |
| Learner | student@acumen.test | Learning-demo-2026! |

Existing email identifiers/passwords are preserved through rebranding. Seeders are local/testing-only and never appropriate for production. They retain account passwords but refresh demonstration roles and reader files. Public signup is disabled. Administrators provision accounts and manage course access. Following the updated product decision, active learners can enroll in published courses from the catalog. Repeated enrollment requests are idempotent. Explicit administrator removals require administrator reinstatement. Share initial credentials through an approved secure channel; they are never written to activity logs.

## Accounts and sessions

Five failed logins within the configured failure window lock the normalized email identifier across IP addresses. The default lock is 15 minutes from the fifth failure, configured by `LMS_LOGIN_LOCKOUT_MINUTES`; successful login clears failures. Separate IP throttling limits all authentication requests. Generic failures avoid exposing whether an account is inactive.

Login regenerates the session and redirects by role. Logout invalidates it. Account deactivation, role/email changes and password changes revoke old sessions through an authentication version and rotated remember token. Reactivation requires a fresh login. Profile/password changes require the current password. Administrators cannot deactivate or demote themselves. Account and course evidence are retained.

## Queue, scheduler and email

```sh
php artisan queue:work database --sleep=1 --tries=3 --timeout=45
php artisan schedule:work
```

Use supervised worker processes and a once-per-minute `php artisan schedule:run` host task in deployment. The code's schedule declaration does not install a host scheduler. The quiz finalizer processes up to 500 expired attempts per run and can also be run with `php artisan quizzes:finalize`.

Password-reset flow uses Laravel's email broker. `.env.example` uses local log mail; configure and verify real SMTP before claiming email delivery. Keep queue retry timing greater than the worker timeout. Serve only `public/`, with private uploads outside the web root.

## AI configuration

`AI_PROVIDER=mock` generates deterministic development output without contacting an AI service. The existing `openai` adapter uses server-side `OPENAI_API_KEY` and `OPENAI_MODEL`. Tests fake external HTTP. Source extraction currently supports lesson text and UTF-8 TXT/Markdown only; PDF downloads do not imply PDF extraction. Notes enforce ownership, quotas, source-version checks and queued access rechecks.

OpenRouter integration, model discovery, secure credential administration, instructor AI questions and approved spending controls are planned for milestone 6. They are not implemented. Never treat mock output as a working production integration or silently select a paid model.

## Verification

```sh
vendor/bin/phpunit
vendor/bin/pint --format agent
composer validate --no-check-publish
php artisan view:cache --no-interaction
php artisan route:list --except-vendor
```

Portable tests use isolated SQLite; the delivered application uses MySQL. MySQL integration tests require a dedicated test database. The destructive upgrade fixture explicitly permits only `acumen_university_test` on MySQL; that retained test database name has no relationship to the active product scope.

## Important remaining work

Material uploads still use the legacy 5 MB PDF/TXT/Markdown policy. Assignments still accept late work. Short-answer quizzes, explicit result publication, platform announcements and full AI administration are not yet delivered. Consult the checklist before using these workflows as SRS-compliant assessment controls.

Production prerequisites include real mail, HTTPS/secure cookies, debug disabled, worker/scheduler supervision, private storage backups with restoration tests, monitoring, load measurement and a security/accessibility review. No deployment, 99% availability, WCAG conformance or concurrency target is claimed.
