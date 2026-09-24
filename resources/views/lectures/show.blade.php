@extends('layouts.app')
@section('title','Video lecture')
@section('content')<a class="back-link" href="{{ route('lectures.index',$lecture->course_id) }}">← All lectures</a>
<div class="page-heading">
<div>
<span class="eyebrow">{{ strtoupper($lecture->status) }} LECTURE</span>
<h1>{{ $lecture->title }}</h1>
<p class="muted">{{ $lecture->lesson?->title ? $lecture->lesson->title.' · ' : '' }}{{ $lecture->duration_seconds ? gmdate($lecture->duration_seconds >= 3600 ? 'G\h i\m s\s' : 'i\m s\s', $lecture->duration_seconds) : 'Duration unknown' }}@if($manage) · uploaded by {{ $lecture->uploader?->name ?? 'unknown' }}@endif</p>
</div>
</div>

@if($lecture->processing_status !== 'ready')<div class="panel empty" role="status">
<h3>{{ $lecture->processing_status === 'failed' ? 'This recording could not be prepared' : 'This recording is still being prepared' }}</h3>
<p class="muted">{{ $lecture->processing_error ?? 'Check back shortly. Playback becomes available once processing finishes.' }}</p>
</div>
@else
<section class="panel video-panel">
<div class="video-frame">
<video id="lecture-player" controls preload="metadata" playsinline
 @if($lecture->poster_path) poster="{{ route('lectures.poster.show',$lecture) }}" @endif
 data-progress-url="{{ route('lectures.progress',$lecture) }}"
 data-can-record="{{ auth()->user()->role === 'student' ? '1' : '0' }}"
 data-resume-at="{{ $progress->position_seconds ?? 0 }}"
 aria-label="Video lecture: {{ $lecture->title }}">
<source src="{{ route('lectures.stream',$lecture) }}" type="{{ $lecture->mime_type }}">
@foreach($lecture->captions as $caption)<track kind="captions" srclang="{{ $caption->language }}" label="{{ $caption->label }}" src="{{ route('lectures.captions',[$lecture,$caption]) }}">@endforeach
<p>Your browser cannot play this video. <a href="{{ route('lectures.stream',$lecture) }}">Open the file directly</a>.</p>
</video>
</div>
<div class="row wrap video-controls">
<label for="lecture-speed">Playback speed</label>
<select id="lecture-speed" data-player-speed>
<option value="0.5">0.5×</option>
<option value="0.75">0.75×</option>
<option value="1" selected>Normal</option>
<option value="1.25">1.25×</option>
<option value="1.5">1.5×</option>
<option value="2">2×</option>
</select>
<span class="muted" data-player-status role="status" aria-live="polite"></span>
</div>
@if(auth()->user()->role === 'student')<div class="row between">
<small class="muted">Viewing progress · counts forward playback only, so skipping ahead does not mark a lecture watched.</small>
<strong data-watched-label>{{ $lecture->duration_seconds ? min(100, round(100 * ($progress->watched_seconds ?? 0) / $lecture->duration_seconds)) : 0 }}%</strong>
</div>
<progress max="100" value="{{ $lecture->duration_seconds ? min(100, round(100 * ($progress->watched_seconds ?? 0) / $lecture->duration_seconds)) : 0 }}" data-watched-bar aria-label="Watched"></progress>@endif
</section>
@endif

@if($lecture->description)<p class="course-description">{{ $lecture->description }}</p>@endif

@php($transcript = $lecture->tracks->firstWhere('kind','transcript'))
@if($transcript && $transcript->transcript_text)<details class="panel" id="transcript">
<summary>Transcript</summary>
<div class="prose transcript">{{ $transcript->transcript_text }}</div>
@if(auth()->user()->role === 'student')<form method="post" action="{{ route('notes.store') }}">@csrf<input type="hidden" name="source_type" value="lecture">
<input type="hidden" name="source_id" value="{{ $lecture->id }}">
<button class="button">✧ Generate study notes from this transcript</button>
</form>@endif
</details>@endif

@if($manage)
<div class="section-heading">
<h2>Lecture management</h2>
</div>
<details class="panel">
<summary>Edit details</summary>
<form method="post" action="{{ route('lectures.update',$lecture) }}">@csrf @method('patch')<input type="hidden" name="version" value="{{ $lecture->version }}">
<label>Title<input name="title" value="{{ $lecture->title }}" required maxlength="160">
</label>
<label>Description<textarea name="description" rows="3" maxlength="5000">{{ $lecture->description }}</textarea>
</label>
<div class="two-col">
<label>Lesson<select name="lesson_id">
<option value="">General course lecture</option>@foreach($lecture->course->lessons as $lesson)<option value="{{ $lesson->id }}" @selected($lecture->lesson_id === $lesson->id)>{{ $lesson->title }}</option>@endforeach</select>
</label>
<label>Display order<input type="number" name="position" min="1" max="10000" value="{{ $lecture->position }}" required>
</label>
</div>
<button class="button">Save details</button>
</form>
</details>

<details class="panel">
<summary>Publication</summary>
<p class="muted">Draft lectures are visible to course staff only. Archived lectures stay on file but learners cannot open them.</p>
<div class="row wrap">
@foreach(['draft' => 'Return to draft','published' => 'Publish to learners','archived' => 'Archive'] as $value => $label)@if($lecture->status !== $value)<form method="post" action="{{ route('lectures.status',$lecture) }}">@csrf<input type="hidden" name="version" value="{{ $lecture->version }}">
<input type="hidden" name="status" value="{{ $value }}">
<button class="button secondary">{{ $label }}</button>
</form>@endif @endforeach
</div>
</details>

<details class="panel">
<summary>Replace the recording</summary>
<form data-upload method="post" enctype="multipart/form-data" action="{{ route('lectures.media',$lecture) }}">@csrf<input type="hidden" name="version" value="{{ $lecture->version }}">
<label>Replacement MP4<input type="file" name="file" accept="video/mp4,.mp4,.m4v" required>
</label>
<label>Reason<input name="reason" required maxlength="1000">
</label>
<progress data-upload-progress max="100" value="0" aria-label="Upload progress" hidden></progress><p data-upload-status role="status" aria-live="polite"></p><button class="button secondary">Replace recording</button>
</form>
@if($revisions->isNotEmpty())<h4>Previous recordings</h4>
<ul class="revision-list">@foreach($revisions as $revision)<li>v{{ $revision->version }} · {{ $revision->original_name }} · replaced {{ \Illuminate\Support\Carbon::parse($revision->replaced_at)->format('M j, Y · H:i') }} UTC by {{ $revision->replaced_by_name ?? 'unknown' }}
<a href="{{ route('lectures.revisions.download',$revision->id) }}">Download ↓</a>
<small class="muted">{{ $revision->reason }}</small>
</li>@endforeach</ul>@endif
</details>

<details class="panel">
<summary>Poster, captions and transcript</summary>
<form data-upload method="post" enctype="multipart/form-data" action="{{ route('lectures.poster',$lecture) }}">@csrf<label>Poster image (JPEG, PNG or WebP)<input type="file" name="poster" accept="image/jpeg,image/png,image/webp" required>
</label>
<progress data-upload-progress max="100" value="0" aria-label="Upload progress" hidden></progress><p data-upload-status role="status" aria-live="polite"></p><button class="button secondary">Save poster</button>
</form>
<form method="post" enctype="multipart/form-data" action="{{ route('lectures.tracks',$lecture) }}">@csrf<input type="hidden" name="kind" value="captions">
<div class="two-col">
<label>Language<input name="language" value="en" required maxlength="16">
</label>
<label>Label<input name="label" value="English" required maxlength="100">
</label>
</div>
<label>WebVTT captions file<input type="file" name="file" accept=".vtt,text/vtt" required>
</label>
<button class="button secondary">Save captions</button>
</form>
<form method="post" action="{{ route('lectures.tracks',$lecture) }}">@csrf<input type="hidden" name="kind" value="transcript">
<div class="two-col">
<label>Language<input name="language" value="en" required maxlength="16">
</label>
<label>Label<input name="label" value="English transcript" required maxlength="100">
</label>
</div>
<label>Transcript text<textarea name="transcript_text" rows="6" maxlength="{{ config('video.transcript_max_chars') }}">{{ $transcript->transcript_text ?? '' }}</textarea>
</label>
<p class="muted">Transcripts are entered or pasted here. Automatic speech-to-text is not implemented.</p>
<button class="button secondary">Save transcript</button>
</form>
</details>

<div class="section-heading">
<h2>Learner viewing progress</h2>
</div>
<section class="panel">@forelse($roster as $row)<div class="student-row">
<div>
<strong>{{ $row->user->name }}</strong>
<small class="muted">{{ $row->user->email }}</small>
</div>
<span>{{ $lecture->duration_seconds ? min(100, round(100 * $row->watched_seconds / $lecture->duration_seconds)) : 0 }}% watched{{ $row->completed ? ' · complete' : '' }}</span>
</div>@empty<p class="muted">No learner has started this lecture yet.</p>@endforelse {{ $roster->withQueryString()->links() }}</section>
@endif
@endsection
