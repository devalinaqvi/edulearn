<?php

namespace Tests\Feature;

use App\Actions\WriteLock;
use RuntimeException;
use Tests\TestCase;

/**
 * Deliberately does NOT use RefreshDatabase: that trait wraps every test in a transaction, which
 * would make the "outside a transaction" case unreachable. No table is touched here, because the
 * guard under test runs before any query.
 */
class WriteLockOutsideTransactionTest extends TestCase
{
    public function test_the_lock_refuses_to_be_taken_outside_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside a database transaction');

        // Outside a transaction the row lock is released immediately, so it serializes nothing.
        WriteLock::acquire();
    }
}
