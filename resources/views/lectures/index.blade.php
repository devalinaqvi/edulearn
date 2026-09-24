@extends('layouts.app')
@section('title','Video lectures')
@section('content')<a class="back-link" href="{{ route('courses.show',$course) }}">← {{ $course->title }}</a>
<div class="page-heading">
<div>
<span class="eyebrow">{{ $course->code }}</span>
<h1>Video lectures</h1>
<p class="muted">{{ $lectures->total() }} {{ Str::plural('lecture',$lectures->total()) }}{{ $manage ? ' · drafts are visible to course staff only' : '' }}</p>
</div>
</div>
@forelse($lectures as $lecture)<a class="panel assignment-link" href="{{ route('lectures.show',$lecture) }}">
<div>
<h3>{{ $lecture->title }}</h3>
<p class="muted">{{ $lecture->lesson?->title ? $lecture->lesson->title.' · ' : '' }}{{ $lecture->duration_seconds ? gmdate($lecture->duration_seconds >= 3600 ? 'G\h i\m' : 'i\m s\s', $lecture->duration_seconds) : 'Duration unknown' }}@if($manage) · {{ ucfirst($lecture->status) }}@endif</p>
@if(!$manage && isset($progress[$lecture->id]))<progress max="100" value="{{ $lecture->duration_seconds ? min(100, round(100 * $progress[$lecture->id]->watched_seconds / $lecture->duration_seconds)) : 0 }}" aria-label="Watched"></progress>@endif
</div>
<span class="badge">{{ $manage ? 'Manage →' : (($progress[$lecture->id]->completed ?? false) ? '✓ Watched' : 'Watch →') }}</span>
</a>@empty<div class="empty panel">{{ $manage ? 'No lectures yet. Upload your first recording below.' : 'No video lectures have been published for this course yet.' }}</div>@endforelse
{{ $lectures->withQueryString()->links() }}
@if($manage)<details class="panel">
<summary>+ Upload a video lecture</summary>
<form data-upload method="post" enctype="multipart/form-data" action="{{ route('lectures.store',$course) }}">@csrf<label>Title<input name="title" required maxlength="160">
</label>
<label>Description (optional)<textarea name="description" rows="3" maxlength="5000">
</textarea>
</label>
<label>Lesson (optional)<select name="lesson_id">
<option value="">General course lecture</option>@foreach($course->lessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title }}</option>@endforeach</select>
</label>
<label>Video file<input type="file" name="file" accept="video/mp4,.mp4,.m4v" required>
</label>
<p class="muted">MP4 only, with H.264 video and AAC audio · up to {{ round(config('video.max_kilobytes')/1024) }} MB. The file contents are validated on the server; other formats must be re-encoded before upload.</p>
<progress data-upload-progress max="100" value="0" aria-label="Upload progress" hidden></progress><p data-upload-status role="status" aria-live="polite"></p><button class="button">Upload lecture</button>
</form>
</details>@endif
@endsection
