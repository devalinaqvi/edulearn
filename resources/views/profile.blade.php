@extends('layouts.app')
@section('title', 'My profile')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YOUR ACCOUNT</span><h1>My profile</h1><p class="muted">Update your details and keep your account secure.</p></div></div>
<div class="two-col">
<form class="panel" method="post" action="{{ route('profile.update') }}">@csrf @method('patch')
<h2>Personal details</h2><label>Name<input name="name" value="{{ old('name', $user->name) }}" required maxlength="100" autocomplete="name"></label>
<label>Email<input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email"></label>
<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><p class="muted">Your administrator manages your role and course enrollment.</p><button class="button">Save profile</button>
</form>
<form class="panel" method="post" action="{{ route('profile.password') }}">@csrf @method('put')
<h2>Change password</h2><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>New password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label><label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" required minlength="10"></label><p class="muted">Use at least ten characters. Changing your password signs out your other sessions.</p><button class="button">Change password</button>
</form>
</div>
@endsection
