@extends('layouts.app')
@section('title', 'Course quizzes')
@section('content')
<a class="back-link" href="{{ route('courses.show', $course) }}">← {{ $course->title }}</a>
<div class="page-heading"><div><span class="eyebrow">ASSESSMENT</span><h1>Course quizzes</h1><p class="muted">Timed multiple-choice practice with one saved attempt per student.</p></div></div>
@forelse($quizzes as $quiz)
<a class="panel assignment-link" href="{{ route('quizzes.show', $quiz->id) }}"><div><h2>{{ $quiz->title }}</h2><p>{{ $quiz->duration_minutes }} minutes · {{ $quiz->opens_at }} to {{ $quiz->closes_at }} UTC</p></div><span class="badge">{{ ucfirst($quiz->status) }}</span></a>
@empty<div class="panel empty">No quizzes available.</div>@endforelse
{{ $quizzes->links() }}
@if($manage)
<section class="panel"><h2>Create a quiz draft</h2><form method="post" action="{{ route('quizzes.store', $course) }}">@csrf
<label>Title<input name="title" maxlength="160" value="{{ old('title') }}" required></label>
<label>Instructions<textarea name="instructions" required>{{ old('instructions') }}</textarea></label>
<div class="two-col"><label>Opens (UTC)<input type="datetime-local" name="opens_at" value="{{ old('opens_at') }}" required></label><label>Closes (UTC)<input type="datetime-local" name="closes_at" value="{{ old('closes_at') }}" required></label></div>
<label>Time limit in minutes<input type="number" name="duration_minutes" min="1" max="240" value="{{ old('duration_minutes', 30) }}" required></label>
<p class="muted">The closing time can shorten a student's attempt. Published quizzes are immutable; review all questions before publishing.</p><button class="button">Create draft</button>
</form></section>
@endif
@endsection
