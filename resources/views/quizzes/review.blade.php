@extends('layouts.app')
@section('title', 'Review quiz attempt')
@section('content')
<a class="back-link" href="{{ route('quizzes.show', $quiz->id) }}">← {{ $quiz->title }}</a><h1>Review quiz attempt</h1>
@if(!$attempt->submitted_at)<p class="panel">This attempt is still in progress. Finalize it before grading.</p>@else
<form class="panel" method="post" action="{{ route('quiz-attempts.grade', $attempt->id) }}">@csrf<input type="hidden" name="version" value="{{ $attempt->version }}">
@foreach($questions as $index => $question)<section><h2>{{ $index + 1 }}. {{ $question['prompt'] }}</h2>@if(($question['type'] ?? 'mcq') === 'short')<div class="prose">{{ $answers[$index] ?? 'Unanswered' }}</div><label>Marks (maximum {{ $question['points'] }})<input type="number" name="scores[{{ $index }}]" step="0.01" min="0" max="{{ $question['points'] }}" value="{{ $scores[$index] ?? '' }}" required></label>@else<p>Response: {{ isset($answers[$index]) ? $question['options'][$answers[$index]] : 'Unanswered' }}</p><p>Correct answer: {{ $question['options'][$question['correct']] }}</p>@endif</section>@endforeach
<label>Feedback<textarea name="feedback" maxlength="10000">{{ $attempt->review_feedback }}</textarea></label><label>Reason for review<input name="reason" required maxlength="1000"></label><button class="button">Save draft review</button></form>
<form class="panel" method="post" action="{{ route('quiz-attempts.publish', $attempt->id) }}" data-confirm="Publish this reviewed result to the learner?">@csrf<input type="hidden" name="version" value="{{ $attempt->version }}"><h2>Publish result</h2><p>Current score: {{ 0 + $attempt->score }}. Short-answer marks must be reviewed. Publication is available after the quiz closes.</p><label>Reason<input name="reason" required maxlength="1000"></label><label><input type="checkbox" name="confirm" value="1" required> I have reviewed this result.</label><button class="button">Publish result</button></form>
@endif
<details class="panel"><summary>Review and publication history</summary>@foreach($history as $change)<p>{{ ucfirst($change->event) }} · {{ $change->created_at }} UTC · {{ $change->reason }}</p>@endforeach</details>
@endsection
