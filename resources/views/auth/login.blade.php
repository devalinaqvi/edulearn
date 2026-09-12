@extends('layouts.app')
@section('title','Sign in')
@section('content')<div class="auth-form">
<span class="eyebrow">WELCOME BACK</span>
<h1>Good to see you again.</h1>
<p class="muted">Sign in to pick up where you left off.</p>
<form method="post" action="/login">@csrf<label>Email address<input name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus>
</label>
<label>Password<input name="password" type="password" autocomplete="current-password" required>
</label>
<div class="row between">
<label class="checkbox">
<input type="checkbox" name="remember" value="1"> Remember me</label>
<a href="{{ route('password.request') }}">Forgot password?</a>
</div>
<button class="button full">Sign in <span>→</span>
</button>
</form>
<p class="muted center">Need an account? Contact your learning administrator.</p>
</div>@endsection
