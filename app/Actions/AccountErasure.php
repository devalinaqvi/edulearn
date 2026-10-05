<?php

namespace App\Actions;

use App\Models\StudyNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Honours a right-to-erasure request without destroying assessment evidence.
 *
 * GDPR Article 17 lets a person ask for their personal data to be removed. Article 17(3) then
 * exempts data an institution must keep to meet a legal obligation or to defend a legal claim,
 * which is what a grade is: a degree has to remain verifiable and an appeal has to remain
 * answerable. The two are reconciled by severing the person from the record rather than deleting
 * the record.
 *
 * So the account survives as a tombstone. Every identifying column is overwritten, and the rows
 * that make up the academic history — enrolments, submissions, attempts, marks and the audit
 * trails around them — are left exactly where they are, now pointing at nobody in particular.
 * That also keeps the twenty-eight restricting foreign keys that reference `users` satisfied, so
 * no history develops a hole.
 *
 * The overwrite is one-way on purpose. Keeping any means of reversing it would make this
 * pseudonymisation, and pseudonymised data is still personal data; it would not discharge the
 * request.
 */
class AccountErasure
{
    /** Reserved by RFC 2606, so an erased address can never reach a real mailbox. */
    private const ERASED_DOMAIN = 'erased.invalid';

    /**
     * @param  array<string, mixed>  $input
     * @return array{removed: array<string, int>, retained: array<string, int>}
     */
    public function erase(User $actor, User $subject, array $input): array
    {
        $this->assertPermitted($actor, $subject, $input);

        return DB::transaction(function () use ($actor, $subject) {
            WriteLock::acquire();
            $current = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            abort_if($current->erased_at !== null, 409, 'This account has already been erased.');

            // Counted before removal so the evidence record can state what was done.
            $retained = $this->assessmentFootprint($current);

            $removed = [
                // Private revision aids with no assessment value and no bearing on a result.
                'study_notes' => StudyNote::where('user_id', $current->id)->count(),
                // Reading receipts are behavioural data, not an academic record.
                'announcement_reads' => DB::table('announcement_reads')->where('user_id', $current->id)->count(),
                // A playback position says where somebody was watching, which is nobody's business.
                'video_progress' => DB::table('video_lecture_progress')->where('user_id', $current->id)->count(),
                'sessions' => DB::table('sessions')->where('user_id', $current->id)->count(),
            ];

            StudyNote::where('user_id', $current->id)->delete();
            DB::table('announcement_reads')->where('user_id', $current->id)->delete();
            DB::table('video_lecture_progress')->where('user_id', $current->id)->delete();
            DB::table('sessions')->where('user_id', $current->id)->delete();

            $current->forceFill([
                'name' => 'Removed account '.$current->id,
                'email' => 'erased-'.$current->id.'@'.self::ERASED_DOMAIN,
                // A random hash no password can produce, rather than an empty or guessable value.
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'email_verified_at' => null,
                'last_login_at' => null,
                'is_active' => false,
                // Invalidates anything still holding a session for this account.
                'auth_version' => $current->auth_version + 1,
                'erased_at' => now(),
                'account_version' => $current->account_version + 1,
            ])->save();

            // Evidence that the request was carried out, naming the administrator who did it and
            // what was affected in counts only. Nothing here identifies the subject.
            DB::table('account_activity')->insert([
                'user_id' => $current->id,
                'actor_id' => $actor->id,
                'event' => 'erased',
                'details' => json_encode(['removed' => $removed, 'retained' => $retained], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return ['removed' => $removed, 'retained' => $retained];
        });
    }

    /** What stays, because it is the academic record rather than the person. */
    private function assessmentFootprint(User $subject): array
    {
        return [
            'enrollments' => DB::table('enrollments')->where('user_id', $subject->id)->count(),
            'submissions' => DB::table('submissions')->where('user_id', $subject->id)->count(),
            'quiz_attempts' => DB::table('quiz_attempts')->where('user_id', $subject->id)->count(),
            'lesson_completions' => DB::table('lesson_completions')->where('user_id', $subject->id)->count(),
        ];
    }

    /** @param array<string, mixed> $input */
    private function assertPermitted(User $actor, User $subject, array $input): void
    {
        abort_unless($actor->is_active && $actor->role === 'admin', 403, 'Only an administrator can erase an account.');
        abort_if($actor->id === $subject->id, 422, 'An administrator cannot erase their own account.');

        // Losing the last administrator would leave nobody able to administer the installation.
        if ($subject->role === 'admin' && User::where('role', 'admin')->where('is_active', true)->whereKeyNot($subject->id)->doesntExist()) {
            abort(422, 'This is the only remaining active administrator. Appoint another before erasing this account.');
        }

        // Typing the address is the confirmation: it cannot be reached by clicking through.
        if (! is_string($input['confirm_email'] ?? null) || ! hash_equals($subject->email, trim($input['confirm_email']))) {
            throw ValidationException::withMessages([
                'confirm_email' => 'Type the account email address exactly to confirm. Erasure cannot be undone.',
            ]);
        }
    }
}
