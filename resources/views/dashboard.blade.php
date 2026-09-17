@extends('layouts.app')
@section('title','Overview')
@section('content')
<div class="page-heading">
<div>
<span class="eyebrow">{{ strtoupper($user->role) }} OVERVIEW</span>
<h1>Keep your curiosity moving, {{ explode(' ', $user->name)[0] }}<span class="green">.</span>
</h1>
<p class="muted">{{ $user->role === 'student' ? 'Your enrolled courses, upcoming work, and saved notes.' : 'Your courses, learners, and teaching tools in one place.' }}</p>
</div>
<a class="button secondary" href="{{ route('courses.index') }}">{{ $user->role === 'student' ? 'View my courses' : 'View courses' }} ↗</a>
</div>
<div class="stats">
<div class="stat">
<span class="stat-icon">▤</span>
<div>
<small>{{ $user->role==='student' ? 'Enrolled courses' : 'Managed courses' }}</small>
<strong>{{ $courses->count() }}</strong>
</div>
</div>
<div class="stat">
<span class="stat-icon peach">✓</span>
<div>
<small>{{ $user->role==='student' ? 'Lessons completed' : 'Total enrollments' }}</small>
<strong>{{ $user->role==='student' ? \App\Models\LessonCompletion::where('user_id',$user->id)->count() : $courses->sum('enrollments_count') }}</strong>
</div>
</div>
<div class="stat">
<span class="stat-icon lilac">✧</span>
<div>
<small>Saved study notes</small>
<strong>{{ \App\Models\StudyNote::where('user_id',$user->id)->where('status','completed')->count() }}</strong>
</div>
</div>
</div>
<div class="section-heading">
<div>
<h2>{{ $user->role==='student' ? 'Continue learning' : 'Your courses' }}</h2>
<p class="muted">{{ $user->role==='student' ? 'Make your next step a meaningful one.' : 'Manage content and follow learner progress.' }}</p>
</div>
<a href="{{ route('courses.index') }}">View all courses →</a>
</div>
<div class="course-grid">@forelse($courses->take(3) as $course)@include('courses.card',['enrolled'=>true])@empty<div class="empty panel">
<h3>Your learning journey is ready.</h3>
<p>Browse the course catalog and enroll in a published course to start learning.</p>
<a href="{{ route('courses.index') }}">View course catalog →</a>
</div>@endforelse</div>
<div class="section-heading">
<div>
<h2>A clearer way to revise</h2>
<p class="muted">Your personal study notes, always within reach.</p>
</div>
<a href="{{ route('notes.index') }}">Open notes library →</a>
</div>
<section class="notes-callout">
<span class="big-spark">✧</span>
<div>
<h3>Less time organizing. More time understanding.</h3>
<p class="muted">Open an enrolled course and choose “Generate study notes” on a lesson or supported material.</p>
<small>Private to you · Grounded in course content · {{ $aiProvider==='mock' ? 'Development mock enabled' : 'AI-generated' }}</small>
</div>
</section>

@if($user->role !== 'student')<section class="panel"><h2>Work awaiting attention</h2><p>{{ $pendingReviews }} submissions awaiting review.</p>@if($activeUsers !== null)<p>{{ $activeUsers }} active accounts. <a href="{{ route('admin') }}">Manage accounts and view activity</a></p>@endif</section>@endif
<div class="two-col">
<section class="panel"><h2>Upcoming assignments</h2>@forelse($upcoming as $assignment)<p><a href="{{ route('assignments.show', $assignment) }}">{{ $assignment->title }}</a><br><small>{{ $assignment->course->title }} · {{ $assignment->due_at->format('M j, Y H:i') }} UTC</small></p>@empty<p class="muted">No upcoming assignments.</p>@endforelse</section>
<section class="panel"><h2>Quiz windows</h2>@forelse($quizzes as $quiz)<p><a href="{{ route('quizzes.show', $quiz->id) }}">{{ $quiz->title }}</a><br><small>{{ $quiz->opens_at }} – {{ $quiz->closes_at }} UTC</small></p>@empty<p class="muted">No upcoming quiz windows.</p>@endforelse</section>
<section class="panel"><h2>Recent materials</h2>@forelse($materials as $material)<p><a href="{{ route('materials.download', $material) }}">{{ $material->title }}</a><br><small>{{ $material->course->title }}</small></p>@empty<p class="muted">No materials available yet.</p>@endforelse</section>
<section class="panel"><h2>Announcements</h2><a href="{{ route('announcements.index') }}">All announcements →</a>@forelse($announcements as $announcement)<h3>{{ $announcement->title }}</h3><p>{{ $announcement->body }}</p><small>{{ $announcement->course?->title ?? 'Platform announcement' }} · {{ $announcement->created_at->format('M j, Y') }}</small>@empty<p class="muted">No course announcements.</p>@endforelse</section>
</div>
@endsection
