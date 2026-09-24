---
paths:
  - 'tests/**'
  - 'phpunit.xml'
---

# Running the test suite safely

`RefreshDatabase` runs `migrate:fresh`. Pointed at the wrong database it destroys application data.

**Always clear the config cache before a MySQL run.** When `bootstrap/cache/config.php` exists,
Laravel stops evaluating `env()`, so a `DB_DATABASE=...` prefix on the phpunit command is silently
ignored and the connection resolves to whatever was cached — the application database. This has
already destroyed `acumen_lms` once.

```sh
php artisan config:clear
DB_CONNECTION=mysql DB_DATABASE=acumen_university_test vendor/bin/phpunit
```

`tests/TestCase.php` enforces this: it aborts the run if the connection resolves to a database
listed in `PROTECTED_DATABASES`. Add any other real database there rather than relying on care.
Verify the effective target with `php artisan config:show database.connections.mysql.database`
before any migrate or seed command, not just tests.

PHPUnit's `<env>` entries do not override variables already present in the environment, which is
why the prefix works at all once the config cache is gone.
