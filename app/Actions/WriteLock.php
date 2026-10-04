<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serializes the mutations that must never interleave.
 *
 * Assessment, enrollment, permission and media changes all take row 1 of `lms_write_locks`
 * before reading anything else, so their read-check-write sequences cannot race each other.
 * The ordering is documented in `.ai/rules/app.md`.
 *
 * The row is created by the online-LMS migration. Acquiring it used to fail open: a query for
 * a row that does not exist returns null rather than raising, so an installation whose tables
 * had been truncated without re-running migrations would run every "serialized" mutation
 * unserialized, silently. Both guarantees are now asserted instead.
 */
class WriteLock
{
    /**
     * Take the shared write lock for the remainder of the surrounding transaction.
     *
     * @throws RuntimeException when called outside a transaction, where the lock would be
     *                          released immediately, or when the coordination row is absent.
     */
    public static function acquire(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('The shared write lock must be acquired inside a database transaction; outside one it is released immediately and serializes nothing.');
        }

        if (! DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new RuntimeException('The lms_write_locks coordination row is missing, so concurrent writes cannot be serialized. Run `php artisan migrate` against this database before making further changes.');
        }
    }
}
