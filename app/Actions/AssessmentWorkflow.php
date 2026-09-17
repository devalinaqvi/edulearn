<?php

namespace App\Actions;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AssessmentWorkflow
{
    public function deadline(Assignment $assignment, int $userId): Carbon
    {
        $extension = DB::table('assignment_extensions')->where('assignment_id', $assignment->id)->where('user_id', $userId)->max('due_at');

        return $extension ? Carbon::parse($extension)->max($assignment->due_at) : $assignment->due_at;
    }

    /** @param array<string, mixed> $input */
    public function rubric(User $actor, Assignment $assignment, array $input): void
    {
        DB::transaction(function () use ($actor, $assignment, $input) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $assignment->course);
            if (isset($input['criteria']) && is_array($input['criteria'])) {
                $input['criteria'] = array_values(array_filter($input['criteria'], fn ($criterion) => ! is_array($criterion) || ! empty($criterion['label']) || ! empty($criterion['max_marks'])));
            }
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'criteria' => 'required|array|min:1|max:20', 'criteria.*' => 'array:label,max_marks', 'criteria.*.label' => 'required|string|max:160', 'criteria.*.max_marks' => 'required|integer|min:1|max:100000'])->validate();
            abort_if((int) $data['version'] !== $assignment->rubric_version || $assignment->submissions()->exists(), 409, 'Rubrics cannot change after submission or from a stale form.');
            if (array_sum(array_column($data['criteria'], 'max_marks')) !== $assignment->max_marks) {
                throw ValidationException::withMessages(['criteria' => 'Criterion marks must total the assignment maximum.']);
            }
            $assignment->update(['rubric' => array_values($data['criteria']), 'rubric_version' => $assignment->rubric_version + 1]);
        });
    }

    /** @param array<string, mixed> $input */
    public function extend(User $actor, Assignment $assignment, array $input): void
    {
        DB::transaction(function () use ($actor, $assignment, $input) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $assignment->course);
            $data = Validator::make($input, ['user_id' => 'required|integer|exists:users,id', 'due_at' => 'required|date', 'reason' => 'required|string|max:1000'])->validate();
            $student = User::findOrFail($data['user_id']);
            $eligible = $student->role === 'student' && $assignment->course->enrollments()->where('user_id', $student->id)->exists();
            abort_unless($eligible, 422, 'Select a currently enrolled learner in this course.');
            if (! Carbon::parse($data['due_at'])->gt($this->deadline($assignment, $student->id))) {
                throw ValidationException::withMessages(['due_at' => 'An extension must be later than the current deadline.']);
            }
            $data['due_at'] = Carbon::parse($data['due_at'])->utc()->format('Y-m-d H:i:s');
            DB::table('assignment_extensions')->insert($data + ['assignment_id' => $assignment->id, 'actor_id' => $actor->id, 'created_at' => now()]);
            Submission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->where('submitted_at', '<=', $data['due_at'])->update(['is_late' => false]);
        });
    }

    /** @param array<string, mixed> $input */
    public function grade(User $actor, Submission $submission, array $input): void
    {
        DB::transaction(function () use ($actor, $submission, $input) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $assignment = Assignment::whereKey($submission->assignment_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $assignment->course);
            $submission->refresh();
            $rules = ['version' => 'required|integer|min:0', 'reason' => 'required|string|max:1000', 'feedback' => 'nullable|string|max:10000'];
            if ($assignment->rubric) {
                $rules['scores'] = 'required|array|size:'.count($assignment->rubric);
                foreach ($assignment->rubric as $index => $criterion) {
                    $rules["scores.$index"] = 'required|numeric|decimal:0,2|min:0|max:'.$criterion['max_marks'];
                }
            } else {
                $rules['grade'] = 'required|numeric|decimal:0,2|min:0|max:'.$assignment->max_marks;
            }
            $data = Validator::make($input, $rules)->validate();
            abort_if((int) $data['version'] !== $submission->grade_version, 409, 'A newer grade was saved. Reload before grading.');
            $scores = $assignment->rubric ? array_map(fn ($index) => $data['scores'][$index], array_keys($assignment->rubric)) : null;
            $grade = $scores ? array_sum(array_map(fn ($score) => (int) round($score * 100), $scores)) / 100 : $data['grade'];
            $before = $submission->only(['grade', 'feedback', 'rubric_scores', 'grade_version']);
            $submission->update(['grade' => $grade, 'rubric_scores' => $scores, 'feedback' => $data['feedback'] ?? null, 'grade_version' => $submission->grade_version + 1, 'status' => 'graded', 'graded_by' => $actor->id, 'graded_at' => now()]);
            DB::table('assessment_grade_changes')->insert(['submission_id' => $submission->id, 'actor_id' => $actor->id, 'before' => json_encode($before), 'after' => json_encode($submission->only(['grade', 'feedback', 'rubric_scores', 'grade_version'])), 'reason' => $data['reason'], 'created_at' => now()]);
        });
    }

    /** @param array<string, mixed> $input */
    public function publishResult(User $actor, Submission $submission, array $input): void
    {
        DB::transaction(function () use ($actor, $submission, $input) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $submission->refresh();
            Gate::forUser($actor->fresh())->authorize('manage', $submission->assignment->course);
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'confirm' => 'accepted', 'reason' => 'required|string|max:1000'])->validate();
            abort_unless($submission->status === 'graded' && $submission->grade !== null, 409, 'Grade the submission before publishing.');
            abort_unless((int) $data['version'] === $submission->grade_version, 409, 'The grade changed. Review the latest grade before publishing.');
            if ($submission->published_grade_version === $submission->grade_version) {
                return;
            }
            $result = $submission->only(['grade', 'feedback', 'rubric_scores']);
            DB::table('result_publications')->insert(['submission_id' => $submission->id, 'actor_id' => $actor->id, 'grade_version' => $submission->grade_version, 'result' => json_encode($result), 'reason' => $data['reason'], 'created_at' => now()]);
            $submission->update(['published_result' => $result, 'published_grade_version' => $submission->grade_version, 'result_published_at' => now()]);
        });
    }
}
