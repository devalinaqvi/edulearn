@extends('layouts.app')
@section('title','Assignment')
@section('content')<a class="back-link" href="{{ route('courses.show',$assignment->course) }}">← {{ $assignment->course->title }}</a>
<div class="page-heading">
<div>
<span class="eyebrow">PUT YOUR KNOWLEDGE INTO PRACTICE</span>
<h1>{{ $assignment->title }}</h1>
<p class="muted">Due @showtime($assignment->due_at) · {{ $assignment->max_marks }} marks</p>
</div>
</div>
<section class="panel">
<h2>Instructions</h2>
<div class="prose">@if($assignment->isRichText()){!! $assignment->instructions !!}@else{!! nl2br(e($assignment->instructions)) !!}@endif</div>
</section>
@if($media->isNotEmpty())
<section class="panel"><h2>Reference material</h2>
<p class="muted">Provided with the brief. These are part of the instructions.</p>
<div class="media-grid">
@foreach($media as $item)
<figure class="media-item">
@if($item->isVideo())
<video controls preload="metadata" playsinline src="{{ route('assignments.media.show', $item) }}">
<p>Your browser cannot play this clip. <a href="{{ route('assignments.media.show', $item) }}">Open it directly</a>.</p>
</video>
<figcaption>{{ $item->alt_text ?: $item->original_name }}@if($item->duration_seconds) · {{ gmdate($item->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $item->duration_seconds) }}@endif</figcaption>
@else
<img src="{{ route('assignments.media.show', $item) }}" alt="{{ $item->alt_text }}">
<figcaption>{{ $item->alt_text }}</figcaption>
@endif
@if($manage)
<form method="post" action="{{ route('assignments.media.destroy', $item) }}" data-confirm="Remove this reference file from the brief?">@csrf @method('delete')<button class="button secondary">Remove</button></form>
@endif
</figure>
@endforeach
</div>
</section>
@endif
@if($assignment->rubric)
<section class="panel"><h2>Marking rubric</h2><ul>@foreach($assignment->rubric as $criterion)<li>{{ $criterion['label'] }} — {{ $criterion['max_marks'] }} marks</li>@endforeach</ul></section>
@endif
@if($manage)
<details class="panel"><summary>Edit this assignment</summary><p>Publishing a course does not lock its content. Title, instructions and the deadline stay editable at any time.</p>
<form method="post" action="{{ route('assignments.update', $assignment) }}">@csrf @method('patch')<input type="hidden" name="version" value="{{ $assignment->version }}">
<label>Title<input name="title" maxlength="160" value="{{ old('title', $assignment->title) }}" required></label>
<label for="assignment-instructions-{{ $assignment->id }}">Instructions</label>
<div class="editor" data-editor>
<div class="editor-toolbar" role="toolbar" aria-label="Formatting" data-editor-toolbar>
<button type="button" data-command="bold" aria-pressed="false" title="Bold (Ctrl+B)"><strong>B</strong></button>
<button type="button" data-command="italic" aria-pressed="false" title="Italic (Ctrl+I)"><em>I</em></button>
<button type="button" data-command="underline" aria-pressed="false" title="Underline (Ctrl+U)"><u>U</u></button>
<button type="button" data-command="formatBlock" data-value="h2" title="Heading">H2</button>
<button type="button" data-command="formatBlock" data-value="h3" title="Subheading">H3</button>
<button type="button" data-command="insertUnorderedList" title="Bulleted list">&bull; List</button>
<button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button>
<button type="button" data-command="formatBlock" data-value="blockquote" title="Quote">&ldquo;&rdquo;</button>
<button type="button" data-command="formatBlock" data-value="pre" title="Code block">&lt;/&gt;</button>
<button type="button" data-command="createLink" title="Add a link">Link</button>
<button type="button" data-command="removeFormat" title="Clear formatting">Clear</button>
</div>
<textarea id="assignment-instructions-{{ $assignment->id }}" name="instructions" rows="8" required data-editor-source>{{ old('instructions', $assignment->instructions) }}</textarea>
<input type="hidden" name="instructions_format" value="{{ $assignment->instructions_format ?? 'text' }}" data-editor-format>
</div>
<p class="muted">Formatting is checked again on the server; anything unsupported is removed when the assignment is saved.</p>
<label>Deadline (@tz)<input type="datetime-local" name="due_at" value="{{ old('due_at', \App\Services\DisplayTime::forInput($assignment->due_at)) }}" required></label>
@if($submissions->isEmpty())
<label>Total marks<input type="number" name="max_marks" min="1" max="100000" value="{{ old('max_marks', $assignment->max_marks) }}" required></label>
@if($assignment->rubric)<p class="muted">Changing the total clears the marking rubric, because criterion marks must add up to it. Re-enter the rubric afterwards.</p>@endif
@else
<label>Total marks<input type="number" name="max_marks" value="{{ $assignment->max_marks }}" readonly aria-describedby="marks-locked" required></label>
<p class="muted" id="marks-locked">The total cannot be changed because learners have already submitted work. Existing grades were recorded against this maximum.</p>
@endif
<button class="button">Save assignment</button></form></details>
@if($submissions->isEmpty())
<details class="panel"><summary>Define marking rubric</summary><p>The criteria must total {{ $assignment->max_marks }} marks. Leave unused rows blank. The rubric is frozen after the first submission.</p>
<form method="post" action="{{ route('assignments.rubric', $assignment) }}">@csrf<input type="hidden" name="version" value="{{ $assignment->rubric_version }}">
@for($i = 0; $i < max(5, count($assignment->rubric ?? [])); $i++)
<div class="two-col"><label>Criterion {{ $i + 1 }}<input name="criteria[{{ $i }}][label]" maxlength="160" value="{{ old('criteria.'.$i.'.label', $assignment->rubric[$i]['label'] ?? '') }}"></label><label>Maximum marks<input type="number" name="criteria[{{ $i }}][max_marks]" min="1" max="{{ $assignment->max_marks }}" value="{{ old('criteria.'.$i.'.max_marks', $assignment->rubric[$i]['max_marks'] ?? '') }}"></label></div>
@endfor
<button class="button">Save rubric</button></form></details>
@endif
<details class="panel"><summary>Publication state</summary>
<p>This assignment is <strong>{{ $assignment->status }}</strong>. Drafts and archived assignments are invisible to learners and cannot be submitted to.</p>
<form method="post" action="{{ route('assignments.status', $assignment) }}">@csrf<input type="hidden" name="version" value="{{ $assignment->version }}">
<fieldset class="choice-group"><legend>Set the state</legend>
<label><input type="radio" name="status" value="draft" @checked($assignment->isDraft()) @disabled($submissions->isNotEmpty())> Draft — not yet issued@if($submissions->isNotEmpty()) (unavailable once work is submitted)@endif</label>
<label><input type="radio" name="status" value="published" @checked($assignment->isPublished())> Published — visible to learners</label>
<label><input type="radio" name="status" value="archived" @checked($assignment->isArchived())> Archived — withdrawn, submissions kept</label>
</fieldset>
<details class="reason-optional"><summary>Add a reason (optional)</summary><label>Reason<input name="reason" maxlength="1000" placeholder="Recorded in the removal history"></label></details>
<button class="button secondary">Save state</button></form>
@if($submissions->isEmpty())
<hr>
<p>No learner has submitted to this assignment, so it can be removed permanently. Reference files attached to the brief are removed with it.</p>
<form method="post" action="{{ route('assignments.destroy', $assignment) }}" data-confirm="Permanently delete &ldquo;{{ $assignment->title }}&rdquo;? This cannot be undone.">@csrf @method('delete')
<input type="hidden" name="version" value="{{ $assignment->version }}"><input type="hidden" name="confirm" value="1">
<details class="reason-optional"><summary>Add a reason (optional)</summary><label>Reason<input name="reason" maxlength="1000" placeholder="Recorded in the deletion history"></label></details>
<button class="button danger">Delete permanently</button></form>
@else
<hr>
<p class="muted">This assignment cannot be deleted because {{ $submissions->count() }} learner {{ \Illuminate\Support\Str::plural('submission', $submissions->count()) }} depend on it. Archive it instead to withdraw it while keeping that work.</p>
@endif
</details>
<details class="panel"><summary>Add reference material</summary>
<p>Attach an image or a short MP4 clip to the brief. Images need a description so that learners using a screen reader receive the same information.</p>
<form method="post" enctype="multipart/form-data" action="{{ route('assignments.media.store', $assignment) }}">@csrf
<fieldset class="choice-group"><legend>What are you attaching?</legend>
<label><input type="radio" name="kind" value="image" checked> An image (JPEG, PNG or WebP)</label>
<label><input type="radio" name="kind" value="video"> A clip (MP4, H.264 video with AAC audio)</label>
</fieldset>
<label>File<input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.mp4,.m4v" required></label>
<label>Image description<input name="alt_text" maxlength="500" placeholder="What the image shows, for anyone who cannot see it"></label>
<p class="muted">Images up to {{ round(config('lms.assignment_media.image_max_kilobytes') / 1024, 1) }} MB, clips up to {{ round(config('lms.assignment_media.video_max_kilobytes') / 1024, 1) }} MB. Up to {{ config('lms.assignment_media.max_per_assignment') }} files per assignment.</p>
<button class="button">Attach to brief</button></form></details>
<details class="panel"><summary>Grant a student deadline extension</summary><p>Extensions can only move deadlines later. Record a brief administrative reason without medical or other sensitive details.</p><form method="post" action="{{ route('assignments.extend', $assignment) }}">@csrf<label>Student<select name="user_id" required><option value="">Select a enrolled learner</option>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->name }} · {{ $student->email }}</option>@endforeach</select></label><label>New deadline (@tz)<input type="datetime-local" name="due_at" required></label><label>Reason (visible to the student)<textarea name="reason" maxlength="1000" required></textarea></label><button class="button">Record extension</button></form></details>
@else @if(now()->gte($deadline))<p class="notice">The deadline has passed. Submissions and replacements are closed.</p>@endif<p class="panel">Your deadline: <strong>@showtime($deadline)</strong></p>
@endif
@if($extensions->isNotEmpty())<details class="panel"><summary>Deadline extension history</summary>@foreach($extensions as $extension)<p><strong>{{ $extension->name }}</strong> · @showtime($extension->due_at)<br>{{ $extension->reason }}</p>@endforeach</details>@endif
@if(!$manage)@php($submission=$submissions->first())<div class="section-heading">
<h2>Your submission</h2>
</div>@if(now()->lt($deadline) && (!$submission || $submission->status!=='graded'))<form class="panel" method="post" enctype="multipart/form-data" action="{{ route('assignments.submit',$assignment) }}">@csrf<p class="muted">Submit text, a file, or both. Late work is blocked. You may replace ungraded work before your deadline. Previous versions remain in your submission history.</p>
<input type="hidden" name="version" value="{{ $submission?->grade_version ?? 0 }}"><label>Your response<textarea name="body" rows="7">{{ old('body',$submission?->body) }}</textarea>
</label>
<label>Attach a file<input type="file" name="file" accept=".txt,.md,.pdf">
</label>
<p class="muted">TXT, Markdown, or PDF · Maximum 5 MB.</p>
<button class="button">{{ $submission ? 'Replace submission' : 'Submit assignment' }} →</button>
</form>@endif @else<div class="section-heading">
<h2>Student submissions</h2>
<span class="muted">{{ $submissions->count() }} received</span>
</div>@endif
@forelse($submissions as $submission)<article class="panel">
<div class="row between">
<h3>{{ $manage ? $submission->user->name : 'Submitted work' }}</h3>
<span class="badge">{{ ucfirst($submission->status) }}{{ $submission->is_late ? ' · Late' : '' }}</span>
</div>
<p class="muted">@showtime($submission->submitted_at)</p>
<div class="prose">{{ $submission->body }}</div>
@if(($revisions[$submission->id] ?? collect())->isNotEmpty())<details><summary>Previous submission versions</summary>@foreach($revisions[$submission->id] as $revision)<section><h4>Version {{ $revision->version + 1 }} · @showtime($revision->submitted_at)</h4><div class="prose">{{ $revision->body }}</div>@if($revision->path)<a href="{{ route('submissions.revisions.download', $revision->id) }}">Download previous attachment</a>@endif</section>@endforeach</details>@endif
@if($submission->path)<p>
<a href="{{ route('submissions.download',$submission) }}">Download attachment ↓</a>
</p>@endif @php($displayResult = $manage && $submission->status === 'graded' ? $submission->only(['grade', 'feedback', 'rubric_scores']) : $submission->published_result)
@if($displayResult)<div class="grade">
<strong>{{ $displayResult['grade'] }} / {{ $assignment->max_marks }}</strong>
@if($displayResult['rubric_scores']) @foreach($assignment->rubric as $index => $criterion)<p>{{ $criterion['label'] }}: {{ $displayResult['rubric_scores'][$index] }} / {{ $criterion['max_marks'] }}</p>@endforeach @endif
<p>{{ $displayResult['feedback'] ?: 'No written feedback provided.' }}</p>
</div>@elseif(!$manage && $submission->status === 'graded')<p>Results are awaiting publication.</p>@endif
@if($manage)<form method="post" action="{{ route('submissions.grade',$submission) }}">@csrf @method('patch')<input type="hidden" name="version" value="{{ $submission->grade_version }}">
@if($assignment->rubric)
@foreach($assignment->rubric as $index => $criterion)<label>{{ $criterion['label'] }} ({{ $criterion['max_marks'] }} marks)<input type="number" name="scores[{{ $index }}]" step="0.01" min="0" max="{{ $criterion['max_marks'] }}" value="{{ $submission->rubric_scores[$index] ?? '' }}" required></label>@endforeach
@else<label>Grade (out of {{ $assignment->max_marks }})<input type="number" step="0.01" min="0" max="{{ $assignment->max_marks }}" name="grade" value="{{ $submission->grade }}" required>
</label>
@endif
<label>Reason for this grading decision (staff audit trail)<textarea name="reason" maxlength="1000" required></textarea></label>
<label>Feedback<textarea name="feedback" rows="3">{{ $submission->feedback }}</textarea>
</label>
<button class="button">Save grade & feedback</button>
</form>
@if($submission->status === 'graded')<p>{{ $submission->published_grade_version === $submission->grade_version ? 'This grade is published.' : 'This grade has unpublished changes.' }}</p>
<form method="post" action="{{ route('submissions.publish', $submission) }}" data-confirm="Publish this reviewed grade and feedback to the learner?">@csrf<input type="hidden" name="version" value="{{ $submission->grade_version }}"><label>Publication reason<input name="reason" required maxlength="1000"></label><label><input type="checkbox" name="confirm" value="1" required> I have reviewed the grade and feedback.</label><button class="button">Publish result</button></form>@endif
@endif
@if(isset($history[$submission->id]))<details><summary>Grade history</summary>@foreach($history[$submission->id] as $change)@php($after=json_decode($change->after, true))<p><strong>{{ $after['grade'] }} / {{ $assignment->max_marks }}</strong> · {{ $change->name }} · @showtime($change->created_at)<br>{{ $change->reason }}<br>{{ $after['feedback'] }}</p>@endforeach</details>@endif
</article>@empty<div class="empty panel">{{ $manage ? 'No submissions received yet.' : 'You have not submitted this assignment yet.' }}</div>@endforelse
@endsection
