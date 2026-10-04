<?php

namespace App\Http\Controllers;

use App\Actions\ContentLifecycle;
use App\Actions\WriteLock;
use App\Http\Requests\SaveCourseRequest;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\Submission;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    public function dashboard(Request $r)
    {
        $user = $r->user();
        abort_if($r->route('dashboard_role') && $r->route('dashboard_role') !== $user->role, 403);
        $courses = Course::with('instructor')->withCount(['lessons', 'enrollments'])
            ->when($user->role === 'student', fn ($q) => $q->where('status', 'published')->whereHas('enrollments', fn ($e) => $e->where('user_id', $user->id)))
            ->when($user->role === 'instructor', fn ($q) => $q->where(fn ($assigned) => $assigned->where('instructor_id', $user->id)->orWhereHas('coInstructors', fn ($instructor) => $instructor->where('users.id', $user->id))))->latest()->get();
        $notes = StudyNote::where('user_id', $user->id)->latest()->limit(3)->get();

        $courseIds = $courses->pluck('id');
        $upcoming = Assignment::with('course')->whereIn('course_id', $courseIds)->where('due_at', '>=', now())->orderBy('due_at')->limit(5)->get();
        $quizzes = DB::table('quizzes')->whereIn('course_id', $courseIds)->where('status', 'published')
            ->where(fn ($open) => $open->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderByRaw('opens_at is null desc')->orderBy('opens_at')->limit(5)->get();
        $materials = Material::with('course')->whereIn('course_id', $courseIds)->where('status', 'active')->latest()->limit(5)->get();
        $announcements = Announcement::visibleTo($user)->with('course')->latest()->limit(5)->get();
        $pendingReviews = $user->role !== 'student' ? Submission::whereHas('assignment', fn ($query) => $query->whereIn('course_id', $courseIds))->where('status', 'submitted')->count() : 0;
        $activeUsers = $user->role === 'admin' ? User::where('is_active', true)->count() : null;

        $aiProvider = app(AiSettings::class)->current()->provider;

        return view('dashboard', compact('aiProvider', 'courses', 'notes', 'user', 'upcoming', 'quizzes', 'materials', 'announcements', 'pendingReviews', 'activeUsers'));
    }

    public function index(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $courses = Course::with('instructor')->withCount('lessons')
            ->when($r->user()->role === 'student', fn ($q) => $q->where('status', 'published'))
            ->when($r->user()->role === 'instructor', fn ($q) => $q->where(fn ($assigned) => $assigned->where('instructor_id', $r->user()->id)->orWhereHas('coInstructors', fn ($instructor) => $instructor->where('users.id', $r->user()->id))))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('title', 'like', '%'.$r->string('q').'%')->orWhere('code', 'like', '%'.$r->string('q').'%')))->latest()->paginate(12)->withQueryString();

        return view('courses.index', compact('courses'));
    }

    public function overview(Course $course): View
    {
        abort_unless($course->status === 'published' || Gate::allows('manage', $course), 404);
        $course->load(['instructor', 'lessons' => fn ($query) => $query->where('status', 'active')->select('id', 'course_id', 'title', 'position')]);
        $canLearn = Gate::allows('view', $course);

        return view('courses.overview', compact('course', 'canLearn'));
    }

    public function create(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return view('courses.form', ['course' => new Course, 'instructors' => User::where('role', 'instructor')->where('is_active', true)->get()]);
    }

    public function store(SaveCourseRequest $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validated();
        $data['instructor_id'] = $r->user()->role === 'admin' ? $data['instructor_id'] : $r->user()->id;

        return redirect()->route('courses.show', Course::create($data))->with('status', 'Course created. Add your first lesson below.');
    }

    public function edit(Course $course)
    {
        Gate::authorize('manage', $course);

        return view('courses.form', ['course' => $course, 'instructors' => User::where('role', 'instructor')->where('is_active', true)->get()]);
    }

    public function update(SaveCourseRequest $r, Course $course)
    {
        Gate::authorize('manage', $course);
        DB::transaction(function () use ($r, $course) {
            WriteLock::acquire();
            $course = $course->fresh();
            Gate::forUser($r->user()->fresh())->authorize('manage', $course);
            $data = $r->validated();
            $course->update($data);
        });

        return redirect()->route('courses.show', $course)->with('status', 'Course updated.');
    }

    public function show(Request $r, Course $course)
    {
        Gate::authorize('view', $course);
        $manage = Gate::allows('manage', $course);
        $course->load(['lessons' => fn ($query) => $query->when(! $manage, fn ($active) => $active->where('status', 'active')), 'materials' => fn ($query) => $query->when(! $manage, fn ($active) => $active->where('status', 'active')), 'assignments.submissions' => fn ($query) => $query->where('user_id', $r->user()->id), 'announcements']);
        $completed = LessonCompletion::where('user_id', $r->user()->id)->pluck('lesson_id')->all();
        $enrollments = collect();
        $materialRevisions = collect();
        if ($manage) {
            $enrollments = $course->enrollments()->with(['user' => fn ($query) => $query->withCount(['lessonCompletions as completed_lessons' => fn ($completion) => $completion->whereIn('lesson_id', $course->lessons->pluck('id'))])])->paginate(30, ['*'], 'roster_page');
            $materialRevisions = DB::table('material_revisions')->leftJoin('users', 'users.id', '=', 'material_revisions.replaced_by')->whereIn('material_id', $course->materials->pluck('id'))->select('material_revisions.*', 'users.name as replaced_by_name')->orderByDesc('material_revisions.version')->get()->groupBy('material_id');
        }

        return view('courses.show', compact('course', 'manage', 'completed', 'enrollments', 'materialRevisions'));
    }

    public function enroll(Request $request, Course $course): RedirectResponse
    {
        DB::transaction(function () use ($request, $course) {
            WriteLock::acquire();
            Gate::forUser($request->user()->fresh())->authorize('enroll', $course->fresh());
            $enrollment = Enrollment::firstOrCreate(['course_id' => $course->id, 'user_id' => $request->user()->id]);
            if ($enrollment->wasRecentlyCreated) {
                DB::table('course_access_changes')->insert(['course_id' => $course->id, 'user_id' => $request->user()->id, 'actor_id' => $request->user()->id, 'action' => 'enroll', 'reason' => 'Learner self-enrollment', 'created_at' => now()]);
            }
        });

        return redirect()->route('courses.show', $course)->with('status', 'You are enrolled. Start learning below.');
    }

    public function lesson(Request $r, Course $course)
    {
        Gate::authorize('manage', $course);
        $course->lessons()->create($this->lessonAttributes($r));

        return back()->with('status', 'Lesson added.');
    }

    public function updateLesson(Request $r, Lesson $lesson)
    {
        Gate::authorize('manage', $lesson->course);
        $lesson->update($this->lessonAttributes($r));

        return back()->with('status', 'Lesson updated.');
    }

    /**
     * Validate a lesson and clean its body before anything is stored.
     *
     * Sanitizing here, rather than at render time, means a body read back from the database has
     * necessarily passed through the allowlist. The editor's own filtering is a convenience for
     * the author and is never trusted.
     *
     * @return array<string, mixed>
     */
    private function lessonAttributes(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:200000',
            'position' => 'required|integer|min:1|max:10000',
            'body_format' => 'nullable|in:text,html',
        ]);

        if (($data['body_format'] ?? 'text') !== 'html') {
            return collect($data)->only(['title', 'position'])->all() + ['body' => $data['body'], 'body_format' => 'text'];
        }

        $clean = RichText::sanitize($data['body']);
        if ($clean === '') {
            throw ValidationException::withMessages(['body' => 'The lesson content is empty once unsupported formatting is removed. Write the lesson text itself.']);
        }

        return collect($data)->only(['title', 'position'])->all() + ['body' => $clean, 'body_format' => 'html'];
    }

    public function archiveLesson(Request $request, Lesson $lesson, ContentLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $lesson->course);

        return back()->with('status', $lifecycle->setLessonArchived($request->user(), $lesson, $request->all()));
    }

    public function destroyLesson(Request $request, Lesson $lesson, ContentLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $lesson->course);
        $course = $lesson->course;
        $result = $lifecycle->deleteLesson($request->user(), $lesson, $request->all());

        return redirect()->route('courses.show', $course)->with('status', $result['message']);
    }

    public function destroy(Request $request, Course $course, ContentLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $course);
        $result = $lifecycle->deleteCourse($request->user(), $course, $request->all());

        return $result['deleted']
            ? redirect()->route('courses.index')->with('status', $result['message'])
            : redirect()->route('courses.show', $course)->with('status', $result['message']);
    }

    public function trash(Request $request, ContentLifecycle $lifecycle): View
    {
        $courses = Course::with('instructor')
            ->when($request->user()->role === 'instructor', fn ($q) => $q->where(fn ($assigned) => $assigned->where('instructor_id', $request->user()->id)->orWhereHas('coInstructors', fn ($i) => $i->where('users.id', $request->user()->id))))
            ->when($request->user()->role === 'student', fn ($q) => $q->whereRaw('1 = 0'))
            ->where('status', 'archived')->latest()->get();

        $lessons = Lesson::with('course')->where('status', 'archived')
            ->whereIn('course_id', Course::query()
                ->when($request->user()->role === 'instructor', fn ($q) => $q->where(fn ($assigned) => $assigned->where('instructor_id', $request->user()->id)->orWhereHas('coInstructors', fn ($i) => $i->where('users.id', $request->user()->id))))
                ->when($request->user()->role === 'student', fn ($q) => $q->whereRaw('1 = 0'))
                ->select('id'))
            ->latest('archived_at')->get();

        $reports = $lessons->mapWithKeys(fn ($lesson) => [$lesson->id => $lifecycle->report('lessons', $lesson->id)]);
        $courseReports = $courses->mapWithKeys(fn ($course) => [$course->id => $lifecycle->report('courses', $course->id)]);

        return view('trash', compact('courses', 'lessons', 'reports', 'courseReports'));
    }

    public function complete(Request $r, Lesson $lesson)
    {
        Gate::authorize('participate', $lesson->course);
        abort_if($lesson->isArchived(), 404);
        if ($r->boolean('completed')) {
            LessonCompletion::firstOrCreate(['lesson_id' => $lesson->id, 'user_id' => $r->user()->id]);
        } else {
            LessonCompletion::where('lesson_id', $lesson->id)->where('user_id', $r->user()->id)->delete();
        }

        return back()->with('status', 'Progress updated.');
    }
}
