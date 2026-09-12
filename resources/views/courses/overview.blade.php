@extends('layouts.app')
@section('title', 'Course details')
@section('content')
<a class="back-link" href="{{ route('courses.index') }}">← Course catalog</a>
<div class="page-heading"><div><span class="eyebrow">ONLINE COURSE</span><h1>{{ $course->title }}</h1><p class="muted">{{ $course->code }}</p><p class="muted">With {{ $course->instructor->name }} · {{ $course->lessons->count() }} lessons · Learn at your own pace</p></div></div>
<section class="panel"><h2>About this course</h2><div class="prose">{{ $course->description }}</div>
@if($canLearn)<a class="button" href="{{ route('courses.show', $course) }}">Open course →</a>@elseif(\Illuminate\Support\Facades\Gate::allows('enroll', $course))<form method="post" action="{{ route('courses.enroll', $course) }}">@csrf<button class="button">Enroll and start learning →</button></form>@else<p class="muted">Contact your administrator to request course access.</p>@endif
</section>
<section class="panel"><h2>Course outline</h2><ol>@forelse($course->lessons as $lesson)<li>{{ $lesson->title }}</li>@empty<li>The instructor is preparing the course outline.</li>@endforelse</ol></section>
@endsection
