@extends('layouts.app')
@section('title','Assignment')
@section('content')<a class="back-link" href="{{ route('courses.show',$assignment->course) }}">← {{ $assignment->course->title }}</a>
<div class="page-heading">
<div>
<span class="eyebrow">PUT YOUR KNOWLEDGE INTO PRACTICE</span>
<h1>{{ $assignment->title }}</h1>
<p class="muted">Due {{ $assignment->due_at->format('M j, Y · H:i') }} UTC · {{ $assignment->max_marks }} marks</p>
</div>
</div>
<section class="panel">
<h2>Instructions</h2>
<div class="prose">{{ $assignment->instructions }}</div>
</section>
@if($assignment->rubric)
<section class="panel"><h2>Marking rubric</h2><ul>@foreach($assignment->rubric as $criterion)<li>{{ $criterion['label'] }} — {{ $criterion['max_marks'] }} marks</li>@endforeach</ul></section>
@endif
@if($manage)
@if($submissions->isEmpty())
<details class="panel"><summary>Define marking rubric</summary><p>The criteria must total {{ $assignment->max_marks }} marks. Leave unused rows blank. The rubric is frozen after the first submission.</p>
<form method="post" action="{{ route('assignments.rubric', $assignment) }}">@csrf<input type="hidden" name="version" value="{{ $assignment->rubric_version }}">
@for($i = 0; $i < max(5, count($assignment->rubric ?? [])); $i++)
<div class="two-col"><label>Criterion {{ $i + 1 }}<input name="criteria[{{ $i }}][label]" maxlength="160" value="{{ old('criteria.'.$i.'.label', $assignment->rubric[$i]['label'] ?? '') }}"></label><label>Maximum marks<input type="number" name="criteria[{{ $i }}][max_marks]" min="1" max="{{ $assignment->max_marks }}" value="{{ old('criteria.'.$i.'.max_marks', $assignment->rubric[$i]['max_marks'] ?? '') }}"></label></div>
@endfor
<button class="button">Save rubric</button></form></details>
@endif
<details class="panel"><summary>Grant a student deadline extension</summary><p>Extensions can only move deadlines later. Record a brief administrative reason without medical or other sensitive details.</p><form method="post" action="{{ route('assignments.extend', $assignment) }}">@csrf<label>Student<select name="user_id" required><option value="">Select a enrolled learner</option>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->name }} · {{ $student->email }}</option>@endforeach</select></label><label>New deadline (UTC)<input type="datetime-local" name="due_at" required></label><label>Reason (visible to the student)<textarea name="reason" maxlength="1000" required></textarea></label><button class="button">Record extension</button></form></details>
@else<p class="panel">Your deadline: <strong>{{ $deadline->format('M j, Y · H:i') }} UTC</strong></p>
@endif
@if($extensions->isNotEmpty())<details class="panel"><summary>Deadline extension history</summary>@foreach($extensions as $extension)<p><strong>{{ $extension->name }}</strong> · {{ $extension->due_at }} UTC<br>{{ $extension->reason }}</p>@endforeach</details>@endif
@if(!$manage)@php($submission=$submissions->first())<div class="section-heading">
<h2>Your submission</h2>
</div>@if(!$submission || $submission->status!=='graded')<form class="panel" method="post" enctype="multipart/form-data" action="{{ route('assignments.submit',$assignment) }}">@csrf<p class="muted">Submit text, a file, or both. Late work is accepted and labeled. You may replace your submission until it is graded. Replacement overwrites your previous text and file.</p>
<label>Your response<textarea name="body" rows="7">{{ old('body',$submission?->body) }}</textarea>
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
<p class="muted">{{ $submission->submitted_at->format('M j, Y · H:i') }} UTC</p>
<div class="prose">{{ $submission->body }}</div>@if($submission->path)<p>
<a href="{{ route('submissions.download',$submission) }}">Download attachment ↓</a>
</p>@endif @if($submission->status==='graded')<div class="grade">
<strong>{{ $submission->grade }} / {{ $assignment->max_marks }}</strong>
@if($submission->rubric_scores)@foreach($assignment->rubric as $index => $criterion)<p>{{ $criterion['label'] }}: {{ $submission->rubric_scores[$index] }} / {{ $criterion['max_marks'] }}</p>@endforeach
@endif
<p>{{ $submission->feedback ?: 'No written feedback provided.' }}</p>
</div>@endif @if($manage)<form method="post" action="{{ route('submissions.grade',$submission) }}">@csrf @method('patch')<input type="hidden" name="version" value="{{ $submission->grade_version }}">
@if($assignment->rubric)
@foreach($assignment->rubric as $index => $criterion)<label>{{ $criterion['label'] }} ({{ $criterion['max_marks'] }} marks)<input type="number" name="scores[{{ $index }}]" step="0.01" min="0" max="{{ $criterion['max_marks'] }}" value="{{ $submission->rubric_scores[$index] ?? '' }}" required></label>@endforeach
@else<label>Grade (out of {{ $assignment->max_marks }})<input type="number" step="0.01" min="0" max="{{ $assignment->max_marks }}" name="grade" value="{{ $submission->grade }}" required>
</label>
@endif
<label>Reason for this grading decision (visible to student)<textarea name="reason" maxlength="1000" required></textarea></label>
<label>Feedback<textarea name="feedback" rows="3">{{ $submission->feedback }}</textarea>
</label>
<button class="button">Save grade & feedback</button>
</form>@endif
@if(isset($history[$submission->id]))<details><summary>Grade history</summary>@foreach($history[$submission->id] as $change)@php($after=json_decode($change->after, true))<p><strong>{{ $after['grade'] }} / {{ $assignment->max_marks }}</strong> · {{ $change->name }} · {{ $change->created_at }} UTC<br>{{ $change->reason }}<br>{{ $after['feedback'] }}</p>@endforeach</details>@endif
</article>@empty<div class="empty panel">{{ $manage ? 'No submissions received yet.' : 'You have not submitted this assignment yet.' }}</div>@endforelse
@endsection
