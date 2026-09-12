@extends('layouts.app')
@section('title', 'Course access')
@section('content')
<a class="back-link" href="{{ route('courses.show', $course) }}">← {{ $course->title }}</a>
<div class="page-heading"><div><span class="eyebrow">LMS ADMINISTRATION</span><h1>Course access</h1><p class="muted">Manage online enrollments and additional instructors. Removing access retains submitted work and progress.</p></div></div>
<form class="panel form-panel" method="post" action="{{ route('admin.access.change', $course) }}">@csrf
<label>Account email<input type="email" name="email" value="{{ old('email') }}" required></label>
<label>Action<select name="action"><option value="enroll">Enroll learner</option><option value="remove">Remove learner access</option><option value="teach">Assign additional instructor</option><option value="unteach">Remove additional instructor</option></select></label>
<label>Reason<textarea name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></label><div class="form-actions"><button class="button">Update access</button><a class="button secondary" href="{{ route('courses.show', $course) }}">Back to course</a></div>
</form>
<section class="panel"><h2>Teaching team</h2><p>Primary instructor: {{ $course->instructor->name }}. <a href="{{ route('courses.edit', $course) }}">Edit course assignment</a></p>@foreach($instructors as $instructor)@if($instructor->id !== $course->instructor_id)<p>{{ $instructor->name }} · {{ $instructor->email }}</p>@endif
@endforeach</section>
<section class="panel"><h2>Enrolled learners</h2>@forelse($enrollments as $enrollment)<div class="student-row"><strong>{{ $enrollment->user->name }}</strong><span>{{ $enrollment->user->email }}</span></div>@empty<p>No learners enrolled.</p>@endforelse{{ $enrollments->links() }}</section>
<section class="panel"><h2>Recent access changes</h2>@forelse($history as $change)<p><strong>{{ $change->name }}</strong> · {{ $change->action }} · {{ $change->created_at }} UTC<br>{{ $change->reason }}</p>@empty<p>No administrative access changes recorded.</p>@endforelse</section>
@endsection
