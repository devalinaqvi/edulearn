<?php

namespace App\Http\Controllers;

use App\Actions\AssessmentWorkflow;
use App\Actions\AssignmentMediaLibrary;
use App\Actions\ContentLifecycle;
use App\Actions\WriteLock;
use App\Models\Assignment;
use App\Models\AssignmentMedium;
use App\Models\Course;
use App\Models\Submission;
use App\Services\DisplayTime;
use App\Services\PrivateMediaStream;
use App\Services\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AcademicController extends Controller
{
    public function assignment(Request $r, Course $course)
    {
        Gate::authorize('manage', $course);
        $data = $r->validate([
            'title' => 'required|string|max:160',
            'instructions' => 'required|string|max:200000',
            'instructions_format' => 'nullable|in:text,html',
            'due_at' => 'required|date',
            'max_marks' => 'required|integer|min:1|max:100000',
        ]);
        $data = DisplayTime::normalize($data, 'due_at') + ['status' => 'draft'];
        $data = $this->withCleanInstructions($data);

        $course->assignments()->create($data);

        return back()->with('status', 'Assignment created as a draft. Publish it when you are ready for learners to see it.');
    }

    /**
     * Clean authored instructions before anything is stored, so a brief read back from the
     * database has necessarily passed through the allowlist.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCleanInstructions(array $data): array
    {
        if (($data['instructions_format'] ?? 'text') !== 'html') {
            return array_merge($data, ['instructions_format' => 'text']);
        }

        $clean = RichText::sanitize($data['instructions']);
        if ($clean === '') {
            throw ValidationException::withMessages([
                'instructions' => 'The instructions are empty once unsupported formatting is removed. Write the brief itself.',
            ]);
        }

        return array_merge($data, ['instructions' => $clean, 'instructions_format' => 'html']);
    }

    public function status(Request $request, Assignment $assignment, ContentLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $assignment->course);

        return back()->with('status', $lifecycle->setAssignmentStatus($request->user(), $assignment, $request->all()));
    }

    public function destroy(Request $request, Assignment $assignment, ContentLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('manage', $assignment->course);
        $course = $assignment->course;
        $result = $lifecycle->deleteAssignment($request->user(), $assignment, $request->all());

        return $result['deleted']
            ? redirect()->route('courses.show', $course)->with('status', $result['message'])
            : redirect()->route('assignments.show', $assignment)->with('status', $result['message']);
    }

    public function update(Request $r, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('manage', $assignment->course);
        $result = app(AssessmentWorkflow::class)->updateAssignment($r->user(), $assignment, $r->all());

        return back()->with('status', $result['rubric_cleared']
            ? 'Assignment updated. The rubric no longer totalled the new maximum and was cleared, so add it again before grading.'
            : 'Assignment updated.');
    }

    public function show(Request $r, Assignment $assignment)
    {
        Gate::authorize('view', $assignment->course);
        $manage = Gate::allows('manage', $assignment->course);
        // A draft has not been issued and an archived assignment has been withdrawn.
        abort_unless($manage || $assignment->isPublished(), 404);
        $submissions = $assignment->submissions()->with('user')->when(! $manage, fn ($q) => $q->where('user_id', $r->user()->id))->get();

        $deadline = app(AssessmentWorkflow::class)->deadline($assignment, $r->user()->id);
        $history = DB::table('assessment_grade_changes')->join('users', 'users.id', '=', 'assessment_grade_changes.actor_id')->whereIn('submission_id', $submissions->pluck('id'))->select('assessment_grade_changes.*', 'users.name')->orderByDesc('assessment_grade_changes.id')->get()->groupBy('submission_id');
        $extensions = DB::table('assignment_extensions')->join('users', 'users.id', '=', 'assignment_extensions.user_id')->where('assignment_id', $assignment->id)->when(! $manage, fn ($query) => $query->where('user_id', $r->user()->id))->select('assignment_extensions.*', 'users.name')->orderByDesc('assignment_extensions.id')->get();

        $students = collect();
        if ($manage) {
            $students = $assignment->course->enrollments()->with('user')->get()->pluck('user');
        }

        if (! $manage) {
            $history = collect();
        }
        $revisions = DB::table('submission_revisions')->whereIn('submission_id', $submissions->pluck('id'))->orderByDesc('version')->get()->groupBy('submission_id');
        $media = AssignmentMedium::where('assignment_id', $assignment->id)->orderBy('position')->orderBy('id')->get();

        return view('assignments.show', compact('revisions', 'assignment', 'manage', 'submissions', 'deadline', 'history', 'extensions', 'students', 'media'));
    }

    public function attachMedia(Request $request, Assignment $assignment, AssignmentMediaLibrary $library): RedirectResponse
    {
        Gate::authorize('manage', $assignment->course);
        $library->attach($request->user(), $assignment, $request->all(), $request->file('file'));

        return back()->with('status', 'Reference file added to the assignment brief.');
    }

    public function detachMedia(Request $request, AssignmentMedium $medium, AssignmentMediaLibrary $library): RedirectResponse
    {
        Gate::authorize('manage', $medium->assignment->course);
        $assignment = $medium->assignment;
        $library->detach($request->user(), $medium);

        return redirect()->route('assignments.show', $assignment)->with('status', 'Reference file removed.');
    }

    /**
     * Serve a reference file to anyone who may read the brief it belongs to.
     *
     * Video goes through the Range-capable stream so a clip can be scrubbed without the whole
     * file being buffered; an image is small enough to return whole.
     */
    public function media(Request $request, AssignmentMedium $medium, PrivateMediaStream $stream): Response
    {
        Gate::authorize('view', $medium->assignment->course);
        abort_unless(Storage::disk('local')->exists($medium->path), 404);

        if ($medium->isVideo()) {
            return $stream->respond(
                Storage::disk('local')->path($medium->path),
                $medium->mime_type,
                $request->header('Range'),
                $request->isMethod('HEAD'),
                $medium->path,
            );
        }

        return response(Storage::disk('local')->get($medium->path), 200, [
            'Content-Type' => $medium->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function submit(Request $r, Assignment $assignment)
    {
        Gate::authorize('participate', $assignment->course);
        abort_unless($assignment->isPublished(), 404);
        $data = $r->validate(['body' => 'nullable|required_without:file|string|max:20000', 'file' => 'nullable|required_without:body|file|max:5120|mimes:txt,md,pdf|extensions:txt,md,pdf']);
        $path = $r->file('file')?->store('submissions', 'local');
        $duplicate = false;
        $hash = hash('sha256', json_encode([$data['body'] ?? null, $r->file('file')?->getClientOriginalName(), $r->hasFile('file') ? hash_file('sha256', $r->file('file')->getRealPath()) : null]));
        try {
            DB::transaction(function () use ($r, $assignment, $data, $path, $hash, &$duplicate) {
                WriteLock::acquire();
                $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($r->user()->fresh())->authorize('participate', $assignment->course);
                if (now()->greaterThanOrEqualTo(app(AssessmentWorkflow::class)->deadline($assignment, $r->user()->id))) {
                    throw ValidationException::withMessages(['deadline' => 'The submission deadline has passed. Late submissions are not accepted.']);
                }
                $submission = Submission::firstOrNew(['assignment_id' => $assignment->id, 'user_id' => $r->user()->id]);
                abort_if($submission->status === 'graded', 409, 'This submission has been graded and cannot be replaced.');
                if ($submission->exists && hash_equals($submission->request_hash ?? '', $hash)) {
                    $duplicate = true;

                    return;
                }
                if ($r->has('version')) {
                    abort_unless((int) $r->input('version') === ($submission->grade_version ?? 0), 409, 'Your submission changed. Reload before replacing it.');
                }
                if ($submission->exists) {
                    DB::table('submission_revisions')->insert(['submission_id' => $submission->id, 'version' => $submission->grade_version, 'body' => $submission->body, 'path' => $submission->path, 'original_name' => $submission->original_name, 'submitted_at' => $submission->submitted_at, 'replaced_at' => now()]);
                    $submission->grade_version++;
                }
                $submission->fill(['body' => $data['body'] ?? null, 'path' => $path, 'original_name' => $r->file('file')?->getClientOriginalName(), 'submitted_at' => now(), 'is_late' => false, 'request_hash' => $hash, 'status' => 'submitted'])->save();
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }
        if ($duplicate && $path) {
            Storage::disk('local')->delete($path);
        }

        return back()->with('status', 'Assignment submitted.');
    }

    public function grade(Request $r, Submission $submission)
    {
        Gate::authorize('manage', $submission->assignment->course);
        app(AssessmentWorkflow::class)->grade($r->user(), $submission, $r->all());

        return back()->with('status', 'Draft grade and feedback saved. Review and publish when ready.');
    }

    public function publishResult(Request $r, Submission $submission): RedirectResponse
    {
        app(AssessmentWorkflow::class)->publishResult($r->user(), $submission, $r->all());

        return back()->with('status', 'Result published to the learner.');
    }

    public function rubric(Request $r, Assignment $assignment): RedirectResponse
    {
        app(AssessmentWorkflow::class)->rubric($r->user(), $assignment, $r->all());

        return back()->with('status', 'Rubric saved.');
    }

    public function extend(Request $r, Assignment $assignment): RedirectResponse
    {
        app(AssessmentWorkflow::class)->extend($r->user(), $assignment, $r->all());

        return back()->with('status', 'Deadline extension recorded.');
    }

    public function download(Request $r, Submission $submission)
    {
        abort_unless(Gate::allows('manage', $submission->assignment->course) || ($r->user()->id === $submission->user_id && Gate::allows('view', $submission->assignment->course)), 403);
        abort_unless($submission->path && Storage::disk('local')->exists($submission->path), 404);

        return Storage::disk('local')->download($submission->path, $submission->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function revision(Request $r, int $revision)
    {
        $record = DB::table('submission_revisions')->find($revision);
        abort_unless($record, 404);
        $submission = Submission::findOrFail($record->submission_id);
        abort_unless(Gate::allows('manage', $submission->assignment->course) || ($r->user()->id === $submission->user_id && Gate::allows('view', $submission->assignment->course)), 403);
        abort_unless($record->path && Storage::disk('local')->exists($record->path), 404);

        return Storage::disk('local')->download($record->path, $record->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
