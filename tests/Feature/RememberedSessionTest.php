<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RememberedSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Restore a session the way a returning browser does: the session store is gone, but the
     * remember cookie survives. Laravel's recaller value is "id|remember_token|password_hash".
     */
    private function returnWithOnlyTheRememberCookie(User $user): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // withCookie, not withUnencryptedCookie: the test client encrypts default cookies the way
        // a browser receives them, so EncryptCookies can decrypt the recaller instead of discarding it.
        return $this->withCookie(
            Auth::getRecallerName(),
            $user->id.'|'.$user->remember_token.'|'.$user->password,
        );
    }

    public function test_a_remembered_learner_whose_auth_version_has_moved_is_not_signed_out(): void
    {
        // Any password change or administrative edit raises auth_version. Because only the login
        // controller wrote that number into the session, every remembered return was rejected.
        $user = User::factory()->create(['role' => 'student', 'auth_version' => 4]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('dashboard.student'));

        $this->returnWithOnlyTheRememberCookie($user->fresh())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_remembered_session_still_loses_access_when_the_account_is_deactivated(): void
    {
        $user = User::factory()->create(['role' => 'student', 'auth_version' => 4]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('dashboard.student'));

        $remembered = $user->fresh();
        $user->forceFill(['is_active' => false])->save();

        $this->returnWithOnlyTheRememberCookie($remembered)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_rotated_remember_token_cannot_restore_a_revoked_session(): void
    {
        $user = User::factory()->create(['role' => 'student', 'auth_version' => 4]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('dashboard.student'));

        // Revocation rotates the remember token, which is what makes seeding the session from a
        // surviving cookie safe: a revoked cookie no longer authenticates at all.
        $stale = $user->fresh();
        $user->forceFill(['auth_version' => 5, 'remember_token' => 'rotated-by-revocation'])->save();

        $this->returnWithOnlyTheRememberCookie($stale)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
