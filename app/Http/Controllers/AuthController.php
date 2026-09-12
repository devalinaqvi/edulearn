<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(LoginRequest $r): RedirectResponse
    {
        $data = $r->safe()->only(['email', 'password']);
        $key = 'login-failures:'.hash('sha256', mb_strtolower(trim($data['email'])));
        $lockKey = $key.':locked';
        if (RateLimiter::tooManyAttempts($lockKey, 1)) {
            abort(429, 'Sign-in is temporarily unavailable. Please try again later.', ['Retry-After' => RateLimiter::availableIn($lockKey)]);
        }
        if (! Auth::attempt($data + ['is_active' => true], $r->boolean('remember'))) {
            $seconds = max(60, (int) config('lms.login_lockout_minutes', 15) * 60);
            if (RateLimiter::hit($key, $seconds) >= config('lms.login_max_failures', 5)) {
                RateLimiter::hit($lockKey, $seconds);
            }

            return back()->withErrors(['email' => 'These credentials do not match an available account.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        RateLimiter::clear($lockKey);
        $r->session()->regenerate();
        $r->session()->put('auth_version', $r->user()->auth_version);
        $r->user()->forceFill(['last_login_at' => now()])->save();
        DB::table('account_activity')->insert(['user_id' => $r->user()->id, 'actor_id' => $r->user()->id, 'event' => 'signed_in', 'created_at' => now()]);

        return redirect()->route('dashboard.'.$r->user()->role);
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email') + ['is_active' => true]);

        return back()->with('status', 'If this account exists, a password reset link has been sent.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'auth_version' => $user->auth_version + 1])->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset ? redirect('/login')->with('status', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
