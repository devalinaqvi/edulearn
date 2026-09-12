<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $user = $request->user()->fresh();
            abort_unless($user->is_active && $user->auth_version === (int) $request->session()->get('auth_version', 0), 403);
            $user->fill($request->safe()->only(['name', 'email']));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->account_version++;
            $user->save();
            DB::table('account_activity')->insert(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => 'profile_updated', 'created_at' => now()]);
        });

        return back()->with('status', 'Profile updated.');
    }

    public function password(ChangePasswordRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $user = $request->user()->fresh();
            abort_unless($user->is_active && $user->auth_version === (int) $request->session()->get('auth_version', 0), 403);
            $user->forceFill(['password' => $request->validated('password'), 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('account_activity')->insert(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => 'password_changed', 'created_at' => now()]);
            $request->user()->refresh();
            $request->session()->regenerate();
            $request->session()->put(['auth_version' => $user->auth_version, 'password_hash_web' => $user->password]);
        });

        return back()->with('status', 'Password changed. Other sessions have been signed out.');
    }
}
