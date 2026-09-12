<article class="course-card">
<div class="course-cover cover-{{ $course->id % 3 }}">
<span class="cover-label">EDULEARN / ONLINE COURSES</span>
<div class="cover-shape" aria-hidden="true">{{ ['◎','✳','◈'][$course->id % 3] }}</div>
<span class="badge">{{ ucfirst($course->status) }}</span>
</div>
<div class="card-content">
<small class="eyebrow">{{ $course->code }} · {{ $course->lessons_count }} LESSONS</small>
<h3>{{ $course->title }}</h3>
<p class="muted clamp">{{ $course->description }}</p>
<div class="instructor">
<span class="mini-avatar">{{ mb_substr($course->instructor->name,0,1) }}</span>{{ $course->instructor->name }}</div>@if(auth()->user()->role==='student' && ($enrolled ?? false))@php($progress=$course->progressFor(auth()->user()))<div class="row between progress-label">
<span>Course progress</span>
<strong>{{ $progress }}%</strong>
</div>
<progress max="100" value="{{ $progress }}">{{ $progress }}%</progress>@endif<div class="card-action">@if(($enrolled ?? false) || auth()->user()->role!=='student')<a href="{{ route('courses.show',$course) }}">{{ auth()->user()->role==='student' ? 'Continue learning' : 'Open course' }} →</a>@else<a href="{{ route('courses.overview', $course) }}">View course details →</a>@endif</div>
</div>
</article>
