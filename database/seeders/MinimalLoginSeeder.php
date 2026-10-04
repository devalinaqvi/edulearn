<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The smallest seed that makes an erased installation usable: one account per role, and the
 * system rows the application cannot run without. No courses, lessons, assessments or media.
 *
 * Roles are the three values the application already recognises in `users.role`, as validated by
 * StoreAccountRequest and UpdateAccountRequest. There is no roles or permissions table and no
 * third-party RBAC package; `student` is displayed as "learner" in the interface.
 *
 * Passwords are assigned through the model's `hashed` cast, so nothing is stored in plain text.
 * These are local development credentials and are never appropriate for a deployed installation.
 */
class MinimalLoginSeeder extends Seeder
{
    public const PASSWORD = 'Learning-demo-2026!';

    /** @var list<array{0: string, 1: string}> role => display name */
    private const ACCOUNTS = [
        ['admin', 'Platform Administrator'],
        ['instructor', 'Course Instructor'],
        ['student', 'Enrolled Learner'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development login accounts are limited to local and testing environments. Provision production accounts through administration instead.');
        }

        // Recreated here as well as by migration, so the seeder alone is enough after an erase.
        DB::table('lms_write_locks')->updateOrInsert(['id' => 1]);
        DB::table('settings')->updateOrInsert(['key' => 'site_name'], ['value' => 'EduLearn']);

        foreach (self::ACCOUNTS as [$role, $name]) {
            $user = User::firstOrCreate(
                ['email' => $role.'@acumen.test'],
                ['name' => $name, 'password' => self::PASSWORD],
            );

            // role is not mass assignable, and an existing account keeps its own password.
            $user->forceFill(['role' => $role, 'is_active' => true])->save();
        }
    }
}
