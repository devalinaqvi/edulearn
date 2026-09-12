<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return view('admin', ['users' => User::orderBy('name')->paginate(30), 'activity' => DB::table('account_activity')->join('users', 'users.id', '=', 'account_activity.user_id')->select('account_activity.*', 'users.name')->orderByDesc('account_activity.id')->limit(20)->get(), 'settings' => DB::table('settings')->pluck('value', 'key')]);
    }

    public function user(UpdateAccountRequest $r, User $user)
    {
        abort_unless($r->user()->role === 'admin', 403);
        DB::transaction(function () use ($r, $user) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            abort_unless($r->user()->fresh()->is_active && $r->user()->fresh()->role === 'admin', 403);
            $user->refresh();
            $data = $r->validated();
            abort_if($user->account_version !== (int) $data['version'], 409, 'This account changed. Reload before saving.');
            abort_if($r->user()->id === $user->id && ($data['role'] !== 'admin' || ! $data['is_active']), 422, 'You cannot deactivate or demote your own administrator account.');
            abort_if($data['role'] !== 'instructor' && (Course::where('instructor_id', $user->id)->exists() || DB::table('course_instructors')->where('user_id', $user->id)->exists()), 422, 'Reassign this instructor’s courses before changing their role.');
            $before = $user->only(['role', 'is_active']);
            $user->forceFill(collect($data)->only(['name', 'email', 'role', 'is_active'])->all());
            if ($user->isDirty(['role', 'is_active', 'email'])) {
                $user->auth_version++;
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->account_version++;
            $user->save();
            DB::table('account_activity')->insert(['user_id' => $user->id, 'actor_id' => $r->user()->id, 'event' => 'account_updated', 'details' => json_encode(['before' => $before, 'after' => $user->only(['role', 'is_active']), 'reason' => $data['reason']]), 'created_at' => now()]);
        });

        return back()->with('status', 'User updated.');
    }

    public function createUser(StoreAccountRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            abort_unless($request->user()->fresh()->is_active && $request->user()->fresh()->role === 'admin', 403);
            $data = $request->validated();
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $user->forceFill(['role' => $data['role']])->save();
            DB::table('account_activity')->insert(['user_id' => $user->id, 'actor_id' => $request->user()->id, 'event' => 'account_created', 'details' => json_encode(['role' => $data['role'], 'reason' => $data['reason']]), 'created_at' => now()]);
        });

        return back()->with('status', 'Account created. Share the initial credentials through your approved secure channel.');
    }

    public function access(Request $request, Course $course): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $enrollments = $course->enrollments()->with('user')->paginate(30);
        $instructors = $course->coInstructors()->get();
        $history = DB::table('course_access_changes')->join('users', 'users.id', '=', 'course_access_changes.user_id')->where('course_id', $course->id)->select('course_access_changes.*', 'users.name')->orderByDesc('course_access_changes.id')->limit(30)->get();

        return view('courses.access', compact('course', 'enrollments', 'instructors', 'history'));
    }

    public function changeAccess(Request $request, Course $course): RedirectResponse
    {
        DB::transaction(function () use ($request, $course) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            abort_unless($request->user()->fresh()->is_active && $request->user()->fresh()->role === 'admin', 403);
            $course = $course->fresh();
            $data = $request->validate(['email' => 'required|email|exists:users,email', 'action' => 'required|in:enroll,remove,teach,unteach', 'reason' => 'required|string|max:1000']);
            $user = User::where('email', $data['email'])->firstOrFail();
            $teaching = in_array($data['action'], ['teach', 'unteach']);
            abort_unless($user->is_active && $user->role === ($teaching ? 'instructor' : 'student'), 422, 'Choose an account with the appropriate learner or instructor role.');
            abort_if($data['action'] === 'unteach' && $course->instructor_id === $user->id, 422, 'Reassign the primary instructor on the course edit screen first.');
            $table = $teaching ? 'course_instructors' : 'enrollments';
            $query = DB::table($table)->where('course_id', $course->id)->where('user_id', $user->id);
            $adding = in_array($data['action'], ['enroll', 'teach']);
            if ($query->exists() === $adding) {
                return;
            }
            if ($adding) {
                $values = ['course_id' => $course->id, 'user_id' => $user->id];
                if (! $teaching) {
                    $values += ['created_at' => now(), 'updated_at' => now()];
                }
                DB::table($table)->insert($values);
            } else {
                $query->delete();
            }
            DB::table('course_access_changes')->insert(['course_id' => $course->id, 'user_id' => $user->id, 'actor_id' => $request->user()->id, 'action' => $data['action'], 'reason' => $data['reason'], 'created_at' => now()]);
        });

        return back()->with('status', 'Course access updated. Learning records have been retained.');
    }

    public function settings(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['site_name' => 'required|string|max:80', 'registration_open' => 'prohibited']);
        foreach ($data as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
        }

        return back()->with('status', 'Settings saved.');
    }
}
