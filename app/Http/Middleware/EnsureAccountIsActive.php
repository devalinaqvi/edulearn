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
