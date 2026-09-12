@extends('layouts.app')
@section('title', 'Administration')
@section('content')
<div class="page-heading"><div><span class="eyebrow">EDULEARN ADMINISTRATION</span><h1>People and platform settings</h1><p class="muted">Provision accounts, manage roles, and review activity.</p></div><a class="button secondary" href="{{ route('courses.index') }}">Manage courses</a></div>
<details class="panel"><summary>Create an account</summary>
<form method="post" action="{{ route('admin.users.store') }}">@csrf
<div class="two-col"><label>Name<input name="name" value="{{ old('name') }}" required maxlength="100"></label><label>Email<input type="email" name="email" value="{{ old('email') }}" required></label></div>
<label>Role<select name="role"><option value="student">Learner</option><option value="instructor">Instructor</option><option value="admin">Administrator</option></select></label>
<div class="two-col"><label>Initial password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Confirm initial password<input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label></div>
<label>Reason<textarea name="reason" required maxlength="1000">{{ old('reason') }}</textarea></label><p class="muted">Share credentials through your approved secure channel. Public account registration is disabled.</p><button class="button">Create account</button>
</form></details>
<details class="panel"><summary>Platform settings</summary><form method="post" action="{{ route('admin.settings') }}">@csrf @method('put')<label>Platform name<input name="site_name" value="{{ $settings['site_name'] ?? 'EduLearn' }}" required maxlength="80"></label><button class="button">Save settings</button></form></details>
<div class="section-heading"><h2>Accounts</h2><span class="muted">{{ $users->total() }} accounts</span></div>
<div class="notice">Deactivation removes access and invalidates sessions. Learning records are retained. Reassign teaching responsibilities before changing an instructor's role.</div>
@foreach($users as $user)
<details class="panel"><summary>{{ $user->name }} · {{ $user->email }} · {{ $user->is_active ? 'Active' : 'Inactive' }}</summary>
<form method="post" action="{{ route('admin.users', $user) }}" data-confirm="Save these account changes? Role, email, or access changes sign this person out.">@csrf @method('patch')<input type="hidden" name="version" value="{{ $user->account_version }}">
<div class="two-col"><label>Name<input name="name" value="{{ $user->name }}" required maxlength="100"></label><label>Email<input type="email" name="email" value="{{ $user->email }}" required></label></div>
<div class="two-col"><label>Role<select name="role">@foreach(['student' => 'Learner', 'instructor' => 'Instructor', 'admin' => 'Administrator'] as $role => $label)<option value="{{ $role }}" @selected($user->role === $role)>{{ $label }}</option>@endforeach</select></label>
<label>Account access<select name="is_active"><option value="1" @selected($user->is_active)>Active</option><option value="0" @selected(!$user->is_active)>Inactive</option></select></label></div>
<label>Reason<textarea name="reason" maxlength="1000" required></textarea></label><p class="muted">Last sign-in: {{ $user->last_login_at ? $user->last_login_at->format('M j, Y H:i').' UTC' : 'Not recorded' }}</p><button class="button secondary">Save account</button>
</form></details>
@endforeach
{{ $users->links() }}
<section class="panel"><h2>Recent account activity</h2><div class="table-scroll"><table><caption>Most recent 20 recorded events</caption><thead><tr><th scope="col">Account</th><th scope="col">Action</th><th scope="col">Time (UTC)</th></tr></thead><tbody>@forelse($activity as $event)<tr><td>{{ $event->name }}</td><td>{{ ucfirst(str_replace('_', ' ', $event->event)) }}</td><td>{{ $event->created_at }}</td></tr>@empty<tr><td colspan="3">No recorded activity yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
