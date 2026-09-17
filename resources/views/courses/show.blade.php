@extends('layouts.app')
@section('title','Course workspace')
@section('content')<a class="back-link" href="{{ route('courses.index') }}">← All courses</a>
<div class="page-heading">
<div>
<span class="eyebrow">{{ strtoupper($course->status) }} COURSE</span>
<h1>{{ $course->title }}</h1><p class="muted">{{ $course->code }}</p>
<p class="muted">With {{ $course->instructor->name }} · {{ $course->lessons->count() }} lessons</p>
</div>@if($manage)<a class="button secondary" href="{{ route('courses.edit',$course) }}">Edit course ↗</a>@endif</div>
@if(auth()->user()->role === 'admin')<p><a class="button secondary" href="{{ route('admin.access', $course) }}">Manage course access</a></p>@endif
<p class="course-description">{{ $course->description }}</p>
@if(!$manage)<section class="panel progress-panel">
<div class="row between">
<h3>Your learning progress</h3>
<strong>{{ $course->progressFor(auth()->user()) }}%</strong>
</div>
<progress max="100" value="{{ $course->progressFor(auth()->user()) }}">
</progress>
<small class="muted">Completed lessons ÷ total lessons. Mark each lesson complete when you are ready; opening it does not count.</small>
</section>@endif
<div class="content-columns">
<div>
<div class="section-heading">
<h2>Course lessons</h2>
<span class="muted">{{ $course->lessons->count() }} chapters</span>
</div>@forelse($course->lessons as $lesson)<details class="panel lesson" id="lesson-{{ $lesson->id }}">
<summary>
<span class="lesson-number">{{ str_pad($lesson->position,2,'0',STR_PAD_LEFT) }}</span>
<strong>{{ $lesson->title }}</strong>
<span class="muted">{{ in_array($lesson->id,$completed) ? '✓ Complete' : '+' }}</span>
</summary>
<div class="lesson-content">
<div class="prose">{{ $lesson->body }}</div>@if(!$manage)<div class="row wrap">
<form method="post" action="{{ route('lessons.complete',$lesson) }}">@csrf<input type="hidden" name="completed" value="{{ in_array($lesson->id,$completed) ? 0 : 1 }}">
<button class="button secondary">{{ in_array($lesson->id,$completed) ? 'Mark incomplete' : '✓ Mark complete' }}</button>
</form>
<form method="post" action="{{ route('notes.store') }}">@csrf<input type="hidden" name="source_type" value="lesson">
<input type="hidden" name="source_id" value="{{ $lesson->id }}">
<button class="button">✧ Generate study notes</button>
</form>
</div>@else<details class="edit-details">
<summary>Edit this lesson</summary>
<form method="post" action="{{ route('lessons.update',$lesson) }}">@csrf @method('patch')<label>Title<input name="title" value="{{ $lesson->title }}" required>
</label>
<label>Position<input name="position" type="number" min="1" value="{{ $lesson->position }}" required>
</label>
<label>Content<textarea name="body" rows="8" required>{{ $lesson->body }}</textarea>
</label>
<button class="button">Update lesson</button>
</form>
</details>@endif</div>
</details>@empty<div class="empty panel">Lessons will appear here when your instructor adds them.</div>@endforelse
@if($manage)<details class="panel">
<summary>+ Add a lesson</summary>
<form method="post" action="{{ route('lessons.store',$course) }}">@csrf<label>Title<input name="title" required maxlength="160">
</label>
<label>Order<input type="number" name="position" min="1" value="{{ ($course->lessons->max('position') ?? 0)+1 }}" required>
</label>
<label>Lesson text<textarea name="body" rows="7" required>
</textarea>
</label>
<button class="button">Add lesson</button>
</form>
</details>@endif
<div class="section-heading">
<h2>Assignments</h2>
<a class="button secondary" href="{{ route('quizzes.index', $course) }}">Quizzes →</a>
</div>@forelse($course->assignments as $assignment)@php($mine=$assignment->submissions->first())<a class="panel assignment-link" href="{{ route('assignments.show',$assignment) }}">
<div>
<h3>{{ $assignment->title }}</h3>
<p class="muted">Due {{ $assignment->due_at->format('M j, Y · H:i') }} UTC · {{ $assignment->max_marks }} marks</p>
</div>
<span class="badge">{{ $mine ? ucfirst($mine->status) : ($manage ? 'Review →' : 'To do →') }}</span>
</a>@empty<div class="empty panel">No assignments yet.</div>@endforelse
@if($manage)<details class="panel">
<summary>+ Create an assignment</summary>
<form method="post" action="{{ route('assignments.store',$course) }}">@csrf<label>Title<input name="title" required>
</label>
<label>Instructions<textarea name="instructions" required rows="4">
</textarea>
</label>
<div class="two-col">
<label>Deadline (UTC)<input type="datetime-local" name="due_at" required>
</label>
<label>Maximum marks<input type="number" name="max_marks" value="100" min="1" max="100000" required>
</label>
</div>
<button class="button">Create assignment</button>
</form>
</details>@endif
@if($manage)<div class="section-heading">
<h2>Enrolled students</h2>
</div>
<section class="panel">@forelse($enrollments as $enrollment)<div class="student-row">
<div>
<strong>{{ $enrollment->user->name }}</strong>
<small class="muted">{{ $enrollment->user->email }}</small>
</div>
<span>{{ $course->lessons->count() ? round(100 * $enrollment->user->completed_lessons / $course->lessons->count()) : 0 }}% complete</span>
</div>@empty<p class="muted">No students have enrolled yet.</p>@endforelse {{ $enrollments->withQueryString()->links() }}</section>@endif
</div>
<aside>
<div class="section-heading">
<h2>Course materials</h2>
</div>
<section class="panel">@forelse($course->materials as $material)<div class="material">
<span class="file-icon">▤</span>
<div>
<strong>{{ $material->title }}</strong>
<small>{{ strtoupper($material->format) }} · Private course material @if($material->size_bytes !== null) · {{ number_format($material->size_bytes / 1024, 1) }} KB @endif</small>
<a href="{{ route('materials.download',$material) }}">Download ↓</a>@if(!$manage && in_array($material->format,['txt','md']))<form method="post" action="{{ route('notes.store') }}">@csrf<input type="hidden" name="source_type" value="material">
<input type="hidden" name="source_id" value="{{ $material->id }}">
<button class="text-button">✧ Generate notes</button>
</form>@endif</div>
</div>@empty<p class="muted">No materials uploaded yet.</p>@endforelse</section>
@if($manage)<details class="panel">
<summary>+ Upload study material</summary>
<form data-upload method="post" enctype="multipart/form-data" action="{{ route('materials.store',$course) }}">@csrf<label>Title<input name="title" required>
</label>
<label>Lesson (optional)<select name="lesson_id">
<option value="">General course material</option>@foreach($course->lessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title }}</option>@endforeach</select>
</label>
<label>File<input type="file" name="file" accept=".txt,.md,.pdf,.docx,.pptx" required>
</label>
<p class="muted">PDF, DOCX, PPTX, TXT or Markdown · 10 MB maximum. AI notes support UTF-8 TXT and Markdown only.</p>
<progress data-upload-progress max="100" value="0" aria-label="Upload progress" hidden></progress><p data-upload-status role="status" aria-live="polite"></p><button class="button">Upload material</button>
</form>
</details>@endif
<div class="section-heading">
<h2>Announcements</h2>
</div>@forelse($course->announcements as $announcement)<article class="panel">
<span class="eyebrow">{{ $announcement->created_at->format('M j, Y') }}</span>
<h3>{{ $announcement->title }}</h3>
<div class="prose">{{ $announcement->body }}</div>
</article>@empty<section class="panel muted">You’re all caught up. No announcements yet.</section>@endforelse
@if($manage)<details class="panel">
<summary>+ Publish announcement</summary>
<form method="post" action="{{ route('announcements.store',$course) }}">@csrf<label>Title<input name="title" required>
</label>
<label>Message<textarea name="body" rows="4" required>
</textarea>
</label>
<button class="button">Publish announcement</button>
</form>
</details>@endif
</aside>
</div>@endsection
