@extends('layouts.app')
@section('title','Reset password')
@section('content')<div class="auth-form">
<h1>Let’s get you back in.</h1>
<p class="muted">Enter your email to request a reset link.</p>
<form method="post" action="{{ route('password.email') }}">@csrf<label>Email address<input name="email" type="email" required>
</label>
<button class="button full">Send reset link →</button>
</form>
<p>
<a href="/login">← Back to sign in</a>
</p>
</div>@endsection
