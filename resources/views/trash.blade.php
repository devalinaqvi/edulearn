@extends('layouts.app')
@section('title','Trash')
@section('content')
<div class="page-heading">
<div>
<span class="eyebrow">ARCHIVED CONTENT</span>
<h1>Trash</h1>
<p class="muted">Archived content is hidden from learners but never discarded. Anything that learner records depend on stays here permanently, so that history remains intact. Content that nothing depends on is removed automatically after {{ config('lms.trash_retention_days') }} days; take an export from the course page first if you want to keep a copy.</p>
</div>
</div>

<div class="section-heading"><h2>Archived lessons</h2><span class="muted">{{ $lessons->count() }}</span></div>
@forelse($lessons as $lesson)
@php($report = $reports[$lesson->id])
<article class="panel">
<div class="row between">
<h3>{{ $lesson->title }}</h3>
<span class="badge">{{ $lesson->course->title }}</span>
</div>
<p class="muted">Archived @showtime($lesson->archived_at)</p>
<div class="row wrap">
<form method="post" action="{{ route('lessons.archive', $lesson) }}">@csrf
<input type="hidden" name="version" value="{{ $lesson->version }}">
<input type="hidden" name="action" value="restore">
<button class="button secondary">Restore lesson</button>
</form>
@if($report['blocked'])
<p class="muted" role="note">{{ $report['summary'] }}</p>
@else
<form method="post" action="{{ route('lessons.destroy', $lesson) }}" data-confirm="Permanently delete “{{ $lesson->title }}”? This cannot be undone.">@csrf @method('delete')
<input type="hidden" name="version" value="{{ $lesson->version }}">
<input type="hidden" name="confirm" value="1">
<details class="reason-optional"><summary>Add a reason (optional)</summary><label>Reason<input name="reason" maxlength="1000" placeholder="Recorded in the deletion history"></label></details>
<button class="button danger">Delete permanently</button>
</form>
<p class="muted">{{ $report['summary'] }}</p>
@endif
</div>
@if($report['detaching'])
<p class="muted">On deletion these would keep existing but lose their link to this lesson: @foreach($report['detaching'] as $item){{ $item['count'] }} {{ $item['label'] }}@if(!$loop->last), @endif @endforeach.</p>
@endif
</article>
@empty
<div class="empty panel">No archived lessons.</div>
@endforelse

<div class="section-heading"><h2>Archived assignments</h2><span class="muted">{{ $assignments->count() }}</span></div>
@forelse($assignments as $assignment)
@php($report = $assignmentReports[$assignment->id])
<article class="panel">
<div class="row between">
<h3><a href="{{ route('assignments.show', $assignment) }}">{{ $assignment->title }}</a></h3>
<span class="badge">{{ $assignment->course->title }}</span>
</div>
<p class="muted">Archived @showtime($assignment->archived_at)</p>
<div class="row wrap">
<form method="post" action="{{ route('assignments.status', $assignment) }}">@csrf
<input type="hidden" name="version" value="{{ $assignment->version }}">
<input type="hidden" name="status" value="published">
<button class="button secondary">Restore and publish</button>
</form>
@if($report['blocked'])
<p class="muted" role="note">{{ $report['summary'] }}</p>
@else
<form method="post" action="{{ route('assignments.destroy', $assignment) }}" data-confirm="Permanently delete &ldquo;{{ $assignment->title }}&rdquo;? This cannot be undone.">@csrf @method('delete')
<input type="hidden" name="version" value="{{ $assignment->version }}"><input type="hidden" name="confirm" value="1">
<details class="reason-optional"><summary>Add a reason (optional)</summary><label>Reason<input name="reason" maxlength="1000" placeholder="Recorded in the deletion history"></label></details>
<button class="button danger">Delete permanently</button>
</form>
<p class="muted">{{ $report['summary'] }}</p>
@endif
</div>
</article>
@empty
<div class="empty panel">No archived assignments.</div>
@endforelse

@if(auth()->user()->role === 'admin')
<div class="section-heading"><h2>Archived courses</h2><span class="muted">{{ $courses->count() }}</span></div>
@forelse($courses as $course)
@php($report = $courseReports[$course->id])
<article class="panel">
<div class="row between">
<h3><a href="{{ route('courses.show', $course) }}">{{ $course->title }}</a></h3>
<span class="badge">{{ $course->code }}</span>
</div>
<p class="muted">{{ $course->instructor->name }}</p>
<div class="row wrap">
<a class="button secondary" href="{{ route('courses.edit', $course) }}">Restore by changing its status</a>
@if($report['blocked'])
<p class="muted" role="note">{{ $report['summary'] }}</p>
@else
<form method="post" action="{{ route('courses.destroy', $course) }}" data-confirm="Permanently delete “{{ $course->title }}”? This cannot be undone.">@csrf @method('delete')
<input type="hidden" name="confirm" value="1">
<details class="reason-optional"><summary>Add a reason (optional)</summary><label>Reason<input name="reason" maxlength="1000" placeholder="Recorded in the deletion history"></label></details>
<button class="button danger">Delete permanently</button>
</form>
<p class="muted">{{ $report['summary'] }}</p>
@endif
</div>
</article>
@empty
<div class="empty panel">No archived courses.</div>
@endforelse
@endif
<div class="section-heading"><h2>Removal history</h2><span class="muted">Most recent {{ $activity->count() }}</span></div>
<section class="panel">
<p class="muted">Who archived, restored or permanently deleted course content, and why. Entries are kept after the content itself is gone, so a grade appeal or an audit can still be answered.</p>
@forelse($activity as $entry)
<article class="history-entry">
<p><strong>{{ ucfirst(str_replace('_', ' ', $entry->action)) }}</strong>
&middot; {{ ucfirst($entry->subject_type) }} &ldquo;{{ $entry->subject_title }}&rdquo;
@if($entry->course_title && $entry->subject_type !== 'course')&middot; {{ $entry->course_title }}@endif
<br><small class="muted">{{ $entry->actor_name ?? 'System' }} &middot; @showtime($entry->created_at)</small></p>
@if($entry->reason)<p>{{ $entry->reason }}</p>@endif
@if($entry->preserved)
@php($preserved = json_decode($entry->preserved, true) ?? [])
@if($preserved)<p class="muted">Preserved: @foreach($preserved as $item){{ $item['count'] }} {{ $item['label'] }}@if(!$loop->last), @endif @endforeach.</p>@endif
@endif
</article>
@empty
<p class="muted">Nothing has been archived or deleted yet.</p>
@endforelse
</section>
@endsection
