<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Databases the suite must never touch. RefreshDatabase runs migrate:fresh, so a
     * misdirected run destroys application data.
     *
     * A cached bootstrap/cache/config.php silently defeats a `DB_DATABASE=...` prefix on the
     * phpunit command, because Laravel stops evaluating env() once the config is cached. This
     * guard checks the database the connection actually resolved to, rather than trusting the
     * command line, and fails the run before any schema is dropped.
     */
    private const PROTECTED_DATABASES = ['acumen_lms'];

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $database = DB::connection()->getDatabaseName();
        if (in_array($database, self::PROTECTED_DATABASES, true) || DB::connection()->getDriverName() !== 'mysql' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against an unsafe database [{$database}].\n".
                "Run `php artisan config:clear` first: a cached config ignores DB_DATABASE.\n".
                'Use an isolated MySQL database whose name ends in _test. Then run: DB_CONNECTION=mysql DB_DATABASE=acumen_university_test vendor/bin/phpunit'
            );
        }

        return $app;
    }
}
