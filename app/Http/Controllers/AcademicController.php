<?php

namespace App\Http\Controllers;

use App\Actions\AssessmentWorkflow;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AcademicController extends Controller
{
    public function assignment(Request $r, Course $course)
    {
        Gate::authorize('manage', $course);
        $course->assignments()->create($r->validate(['title' => 'required|string|max:160', 'instructions' => 'required|string|max:20000', 'due_at' => 'required|date', 'max_marks' => 'required|integer|min:1|max:100000']));

        return back()->with('status', 'Assignment created.');
    }

    public function show(Request $r, Assignment $assignment)
    {
        Gate::authorize('view', $assignment->course);
        $manage = Gate::allows('manage', $assignment->course);
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

        return view('assignments.show', compact('revisions', 'assignment', 'manage', 'submissions', 'deadline', 'history', 'extensions', 'students'));
    }

    public function submit(Request $r, Assignment $assignment)
    {
        Gate::authorize('participate', $assignment->course);
        $data = $r->validate(['body' => 'nullable|required_without:file|string|max:20000', 'file' => 'nullable|required_without:body|file|max:5120|mimes:txt,md,pdf|extensions:txt,md,pdf']);
        $path = $r->file('file')?->store('submissions', 'local');
        $duplicate = false;
        $hash = hash('sha256', json_encode([$data['body'] ?? null, $r->file('file')?->getClientOriginalName(), $r->hasFile('file') ? hash_file('sha256', $r->file('file')->getRealPath()) : null]));
        try {
            DB::transaction(function () use ($r, $assignment, $data, $path, $hash, &$duplicate) {
                DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
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
