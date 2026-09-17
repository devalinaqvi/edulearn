@extends('layouts.app')
@section('title','Courses')
@section('content')<div class="page-heading">
<div>
<span class="eyebrow">EXPAND YOUR HORIZONS</span>
<h1>{{ auth()->user()->role==='student' ? 'Find your next possibility.' : 'Your teaching workspace.' }}</h1>
<p class="muted">{{ auth()->user()->role==='student' ? 'Explore published courses and make room for something new.' : 'Manage course content and published learning.' }}</p>
</div>@if(auth()->user()->role==='admin')<a class="button" href="{{ route('courses.create') }}">+ Create course</a>@endif</div>
<form class="search" method="get">
<input aria-label="Search courses" name="q" placeholder="Search by title or course code…" value="{{ request('q') }}">
<button class="button secondary">Search</button>
</form>
<div class="course-grid">@forelse($courses as $course)@include('courses.card',['enrolled'=>$course->enrollments()->where('user_id',auth()->id())->exists()])@empty<div class="empty panel">
<h3>No courses found.</h3>
<p>Try another search or check back for newly published courses.</p>
</div>@endforelse</div>{{ $courses->links() }}@endsection
