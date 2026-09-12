@extends('layouts.app')
@section('title', $course->exists ? 'Edit course' : 'Create course')
@section('content')<div class="page-heading">
<div>
<span class="eyebrow">BUILD A PATH TO UNDERSTANDING</span>
<h1>{{ $course->exists ? 'Refine your course.' : 'Share what you know.' }}</h1>
</div>
</div>
<form class="panel form-panel" method="post" action="{{ $course->exists ? route('courses.update',$course) : route('courses.store') }}">@csrf @if($course->exists)@method('put')@endif<label>Course code<input name="code" value="{{ old('code', $course->code) }}" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" aria-describedby="course-code-help"></label><small id="course-code-help" class="muted">Unique code using letters, numbers, hyphens or underscores.</small><label>Course title<input name="title" value="{{ old('title',$course->title) }}" required maxlength="160">
</label>
<label>Description<textarea name="description" rows="5" required>{{ old('description',$course->description) }}</textarea>
</label>
<div class="two-col">
<label>Status<select name="status">@foreach(['draft','published','archived'] as $status)<option value="{{ $status }}" @selected(old('status',$course->status)===$status)>{{ ucfirst($status) }}</option>@endforeach</select>
</label>@if(auth()->user()->role==='admin')<label>Assigned instructor<select name="instructor_id" required>
<option value="">Choose an instructor</option>@foreach($instructors as $instructor)<option value="{{ $instructor->id }}" @selected(old('instructor_id',$course->instructor_id)==$instructor->id)>{{ $instructor->name }}</option>@endforeach</select>
</label>@endif</div>
<p class="muted">Draft and archived content is restricted to course managers. Archiving preserves enrollments, submissions, and grades.</p>
<div class="form-actions"><button class="button">Save course →</button><a class="button secondary" href="{{ $course->exists ? route('courses.show', $course) : route('courses.index') }}">Cancel</a></div>
</form>@endsection
