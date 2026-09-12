<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Workspace') · {{ \Illuminate\Support\Facades\DB::table('settings')->where('key','site_name')->value('value') ?? config('app.name') }}</title>
<link rel="stylesheet" href="{{ asset('app.css') }}">
<script src="{{ asset('app.js') }}" defer>
</script>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
@auth
<aside class="sidebar">
<a class="brand" href="{{ route('dashboard') }}">
<span class="brand-mark">e</span>EduLearn
</a>
<div class="workspace-label">YOUR LEARNING WORKSPACE</div>
<nav aria-label="Main navigation">
<a class="{{ request()->routeIs('dashboard*') ? 'active' : '' }}" href="{{ route('dashboard') }}">
<span>◫</span> Overview</a>
<a class="{{ request()->routeIs('courses.*','assignments.*','quizzes.*') ? 'active' : '' }}" href="{{ route('courses.index') }}">
<span>▤</span> {{ auth()->user()->role === 'student' ? 'Course catalog' : 'Manage courses' }}</a>
<a class="{{ request()->routeIs('notes.*') ? 'active' : '' }}" href="{{ route('notes.index') }}">
<span>✧</span> My study notes</a>
<a href="{{ route('profile.show') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}"><span>○</span> My profile</a>@if(auth()->user()->role === 'admin')<a class="{{ request()->routeIs('admin*') ? 'active' : '' }}" href="{{ route('admin') }}">
<span>⚙</span> Administration</a>@endif</nav>
<div class="sidebar-tip">
<span class="spark">✧</span>
<h3>A little clarity.<br>A lot of possibility.</h3>
<p>Turn your course materials into focused revision notes.</p>
<a href="{{ route('notes.index') }}">Explore study notes ↗</a>
</div>
<div class="profile">
<span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span>
<div>
<strong>{{ auth()->user()->name }}</strong>
<small>{{ ucfirst(auth()->user()->role).' learning account' }}</small>
</div>
<form method="post" action="{{ route('logout') }}">@csrf<button class="logout" title="Sign out" aria-label="Sign out">↪</button>
</form>
</div>
</aside>
<div class="shell">
<header class="topbar">
<span>Workspace <span class="crumb">/</span> @yield('title','Overview')</span>
<span class="top-right">
<i class="online-dot">
</i> {{ now()->format('l, M j') }}</span>
</header>
<main id="main">
@else
<div class="auth-shell">
<section class="auth-story">
<a class="brand" href="/">
<span class="brand-mark">e</span>EduLearn</a>
<div>
<span class="eyebrow">MAKE ROOM FOR WHAT’S NEXT</span>
<h1>Your next<br>chapter starts<br>with curiosity.</h1>
<p>A thoughtful space to learn, practice, and turn new ideas into lasting knowledge.</p>
<div class="orbit-art" aria-hidden="true">
<span>✧</span>
</div>
</div>
<small>LEARN WITH PURPOSE. GROW AT YOUR PACE.</small>
</section>
<main class="auth-content" id="main">
@endauth
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">
<strong>Please check the following:</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>@endif
@yield('content')
</main>@auth<footer>Built for focused learning. <span>EduLearn</span>
</footer>
</div>@else</div>@endauth
</body>
</html>
