<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        // A session restored from the remember cookie never passed through AuthController::login,
        // so it carries no auth_version and would be rejected here for every account whose
        // version has ever moved. The remember token is itself rotated on each revocation, so a
        // cookie that still authenticates proves no revocation has happened: seed the session
        // from the account instead of signing the learner out.
        if ($user && Auth::viaRemember() && $request->session()->missing('auth_version')) {
            $request->session()->put('auth_version', $user->auth_version);
        }

        if (! $user || ! $user->is_active || (int) $request->session()->get('auth_version', 0) !== $user->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please sign in again.'], 401);
            }

            return redirect()->route('login')->withErrors(['email' => 'Please sign in again. If access is unavailable, contact your administrator.']);
        }
        Auth::setUser($user);

        return $next($request);
    }
}
