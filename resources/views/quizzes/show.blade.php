@extends('layouts.app')
@section('title', 'Quiz')
@section('content')
<a class="back-link" href="{{ route('quizzes.index', $course) }}">← Course quizzes</a>
<div class="page-heading"><div><span class="eyebrow">{{ strtoupper($quiz->status) }} QUIZ</span><h1>{{ $quiz->title }}</h1><p class="muted">{{ $quiz->duration_minutes }} minutes · {{ $maximum }} marks · One attempt</p><p class="muted">Available {{ $quiz->opens_at }} to {{ $quiz->closes_at }} UTC</p></div></div>
<section class="panel prose">{{ $quiz->instructions }}</section>
@if($manage)
@foreach($questions as $index => $question)
<section class="panel"><h2>{{ $index + 1 }}. {{ $question['prompt'] }}</h2><p>{{ $question['points'] }} marks</p><ol type="A">@foreach($question['options'] as $optionIndex => $option)<li>{{ $option }} {{ $optionIndex === (int) $question['correct'] ? '✓ Correct answer' : '' }}</li>@endforeach</ol>
@if($quiz->status === 'draft')<form method="post" action="{{ route('quizzes.author', $quiz->id) }}">@csrf<input type="hidden" name="version" value="{{ $quiz->version }}"><input type="hidden" name="action" value="remove"><input type="hidden" name="index" value="{{ $index }}"><button class="button secondary">Remove question</button></form>@endif
</section>
@endforeach
@if($quiz->status === 'draft')
<section class="panel"><h2>Add a question</h2><form method="post" action="{{ route('quizzes.author', $quiz->id) }}">@csrf<input type="hidden" name="version" value="{{ $quiz->version }}"><input type="hidden" name="action" value="question">
<label>Question<textarea name="prompt" required>{{ old('prompt') }}</textarea></label>
@for($i = 0; $i < 4; $i++)<label>Option {{ chr(65 + $i) }}<input name="options[{{ $i }}]" value="{{ old('options.'.$i) }}" required maxlength="1000"></label>@endfor
<label>Correct option<select name="correct">@for($i = 0; $i < 4; $i++)<option value="{{ $i }}">{{ chr(65 + $i) }}</option>@endfor</select></label>
<label>Marks<input type="number" name="points" value="{{ old('points', 1) }}" min="1" max="100" required></label><button class="button">Add question</button></form></section>
<section class="panel"><h2>Publish quiz</h2><p>Review the schedule, questions, and answer key. Publishing freezes the quiz and makes it visible to enrolled students.</p><form method="post" action="{{ route('quizzes.author', $quiz->id) }}">@csrf<input type="hidden" name="version" value="{{ $quiz->version }}"><input type="hidden" name="action" value="publish"><button class="button" @disabled(!$questions)>Publish quiz</button></form></section>
@endif
<section class="panel"><h2>Student attempts</h2>@forelse($results as $result)<p><strong>{{ $result->name }}</strong> · {{ $result->submitted_at ? $result->score.' / '.$maximum : 'In progress or awaiting deadline finalization' }} · Deadline {{ $result->deadline_at }} UTC</p>@empty<p>No attempts yet.</p>@endforelse{{ $results->links() }}</section>
@elseif(!$attempt)
<section class="panel"><h2>Ready to begin?</h2><p>The timer begins when you start. Save answers regularly; reopening the quiz resumes the same attempt. The server stops accepting answers at the deadline.</p><form method="post" action="{{ route('quizzes.start', $quiz->id) }}">@csrf<button class="button">Start timed attempt</button></form></section>
@elseif($attempt->submitted_at)
<section class="panel"><h2>Your result</h2><p class="grade">{{ $attempt->score }} / {{ $maximum }}</p><p>Submitted {{ $attempt->submitted_at }} UTC. Your answers are now locked.</p></section>
@else
<section class="panel"><h2>{{ $expired ? 'Time has expired' : 'Your attempt' }}</h2><p>Deadline: <strong>{{ $attempt->deadline_at }} UTC</strong></p><p>Save answers before the deadline. If time expires, finalization scores only answers already saved on the server. Unanswered questions score zero.</p></section>
<form method="post" action="{{ route('quizzes.answer', $quiz->id) }}">@csrf<input type="hidden" name="version" value="{{ $attempt->version }}">
@foreach($questions as $index => $question)<fieldset class="panel" @disabled($expired)><legend>{{ $index + 1 }}. {{ $question['prompt'] }} ({{ $question['points'] }} marks)</legend>
@foreach($question['options'] as $optionIndex => $option)<label><input type="radio" name="answers[{{ $index }}]" value="{{ $optionIndex }}" @checked(isset($answers[$index]) && (int) $answers[$index] === $optionIndex)> {{ $option }}</label>@endforeach
<label><input type="radio" name="answers[{{ $index }}]" value="" @checked(!isset($answers[$index]))> Leave unanswered</label>
</fieldset>@endforeach
<div class="row wrap">@if(!$expired)<button class="button secondary" name="action" value="save">Save answers & continue</button>@endif<button class="button" name="action" value="submit">{{ $expired ? 'Finalize saved answers' : 'Submit final answers' }}</button></div>
</form>
@endif
@endsection
