<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCourseRequest;
use App\Http\Requests\UploadMaterialRequest;
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
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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
        $quizzes = DB::table('quizzes')->whereIn('course_id', $courseIds)->where('status', 'published')->where('closes_at', '>', now())->orderBy('opens_at')->limit(5)->get();
        $materials = Material::with('course')->whereIn('course_id', $courseIds)->latest()->limit(5)->get();
        $announcements = Announcement::with('course')->whereIn('course_id', $courseIds)->latest()->limit(5)->get();
        $pendingReviews = $user->role !== 'student' ? Submission::whereHas('assignment', fn ($query) => $query->whereIn('course_id', $courseIds))->where('status', 'submitted')->count() : 0;
        $activeUsers = $user->role === 'admin' ? User::where('is_active', true)->count() : null;

        return view('dashboard', compact('courses', 'notes', 'user', 'upcoming', 'quizzes', 'materials', 'announcements', 'pendingReviews', 'activeUsers'));
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
        $course->load(['instructor', 'lessons' => fn ($query) => $query->select('id', 'course_id', 'title', 'position')]);
        $canLearn = Gate::allows('view', $course);

        return view('courses.overview', compact('course', 'canLearn'));
    }

    public function create(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['admin', 'instructor']), 403);

        return view('courses.form', ['course' => new Course, 'instructors' => User::where('role', 'instructor')->where('is_active', true)->get()]);
    }

    public function store(SaveCourseRequest $r)
    {
        abort_unless(in_array($r->user()->role, ['admin', 'instructor']), 403);
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
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
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
        $course->load(['lessons', 'materials', 'assignments.submissions' => fn ($query) => $query->where('user_id', $r->user()->id), 'announcements']);
        $manage = Gate::allows('manage', $course);
        $completed = LessonCompletion::where('user_id', $r->user()->id)->pluck('lesson_id')->all();
        $enrollments = collect();
        if ($manage) {
            $enrollments = $course->enrollments()->with(['user' => fn ($query) => $query->withCount(['lessonCompletions as completed_lessons' => fn ($completion) => $completion->whereIn('lesson_id', $course->lessons->pluck('id'))])])->paginate(30, ['*'], 'roster_page');
        }

        return view('courses.show', compact('course', 'manage', 'completed', 'enrollments'));
    }

    public function enroll(Request $request, Course $course): RedirectResponse
    {
        DB::transaction(function () use ($request, $course) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
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
        $course->lessons()->create($r->validate(['title' => 'required|string|max:160', 'body' => 'required|string|max:40000', 'position' => 'required|integer|min:1|max:10000']));

        return back()->with('status', 'Lesson added.');
    }

    public function updateLesson(Request $r, Lesson $lesson)
    {
        Gate::authorize('manage', $lesson->course);
        $lesson->update($r->validate(['title' => 'required|string|max:160', 'body' => 'required|string|max:40000', 'position' => 'required|integer|min:1|max:10000']));

        return back()->with('status', 'Lesson updated.');
    }

    public function complete(Request $r, Lesson $lesson)
    {
        Gate::authorize('participate', $lesson->course);
        if ($r->boolean('completed')) {
            LessonCompletion::firstOrCreate(['lesson_id' => $lesson->id, 'user_id' => $r->user()->id]);
        } else {
            LessonCompletion::where('lesson_id', $lesson->id)->where('user_id', $r->user()->id)->delete();
        }

        return back()->with('status', 'Progress updated.');
    }

    public function material(UploadMaterialRequest $r, Course $course)
    {
        Gate::authorize('manage', $course);
        $data = $r->validated();
        $file = $r->file('file');
        $path = $file->store('materials', 'local');
        abort_unless($path, 500, 'Upload failed.');
        try {
            $course->materials()->create(['uploader_id' => $r->user()->id, 'size_bytes' => $file->getSize(), 'uploaded_at' => now(), 'title' => $data['title'], 'lesson_id' => $data['lesson_id'] ?? null, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'format' => strtolower($file->getClientOriginalExtension())]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('status', 'Material uploaded securely. PDF, DOCX and PPTX downloads are supported; AI extraction supports TXT and Markdown only.');
    }

    public function download(Material $material)
    {
        Gate::authorize('view', $material->course);
        abort_unless(Storage::disk('local')->exists($material->path), 404);

        return Storage::disk('local')->download($material->path, $material->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
