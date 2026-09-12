@extends('layouts.app')
@section('title','Choose password')
@section('content')<div class="auth-form">
<h1>A fresh start.</h1>
<form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}">
<label>Email<input type="email" name="email" value="{{ request('email') }}" required>
</label>
<label>New password<input type="password" name="password" autocomplete="new-password" minlength="10" required>
</label>
<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required>
</label>
<button class="button full">Reset password</button>
</form>
</div>@endsection
