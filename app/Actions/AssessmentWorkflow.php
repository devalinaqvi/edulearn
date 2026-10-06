<?php

namespace App\Actions;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use App\Services\DisplayTime;
use App\Services\RichText;
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

    /**
     * Edit an assignment after it has been created, including inside a published course.
     *
     * Course publication is a visibility state and never withdraws authoring rights. What is
     * constrained here is only what would reinterpret work students have already done:
     *
     *  - title, instructions and the deadline stay editable throughout. Moving the deadline does
     *    not restate existing submissions: their recorded lateness is historical fact.
     *  - max_marks is frozen once anything has been submitted, because every recorded grade was
     *    awarded against it and rubric criteria are validated to total it. Changing it would
     *    silently rescale results that have already been published to learners.
     *  - before any submission exists a rubric is still draft content. If the new maximum no
     *    longer matches its criterion total the rubric is cleared rather than left inconsistent,
     *    and the caller is told so it can be re-entered.
     *
     * @param  array<string, mixed>  $input
     * @return array{rubric_cleared: bool}
     */
    public function updateAssignment(User $actor, Assignment $assignment, array $input): array
    {
        return DB::transaction(function () use ($actor, $assignment, $input) {
            WriteLock::acquire();
            $current = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->course);

            $input = DisplayTime::normalize($input, 'due_at');
            $data = Validator::make($input, [
                'version' => 'required|integer|min:0',
                'title' => 'required|string|max:160',
                'instructions' => 'required|string|max:200000',
                'instructions_format' => 'nullable|in:text,html',
                'due_at' => 'required|date',
                'max_marks' => 'required|integer|min:1|max:100000',
            ])->validate();

            abort_if((int) $data['version'] !== $current->version, 409, 'This assignment changed. Reload before saving.');

            $maximum = (int) $data['max_marks'];
            if ($maximum !== $current->max_marks && $current->hasRecordedWork()) {
                throw ValidationException::withMessages([
                    'max_marks' => 'The total marks cannot be changed because learners have already submitted work for this assignment. Existing grades were recorded against the current maximum.',
                ]);
            }

            $rubricCleared = false;
            $rubric = $current->rubric;
            if ($rubric && $maximum !== (int) array_sum(array_column($rubric, 'max_marks'))) {
                // Unreachable once work exists: the guard above already froze the maximum.
                $rubric = null;
                $rubricCleared = true;
            }

            $richText = ($data['instructions_format'] ?? 'text') === 'html';
            $instructions = $richText ? RichText::sanitize($data['instructions']) : $data['instructions'];
            if ($richText && $instructions === '') {
                throw ValidationException::withMessages([
                    'instructions' => 'The instructions are empty once unsupported formatting is removed. Write the brief itself.',
                ]);
            }

            $current->update([
                'title' => $data['title'],
                'instructions' => $instructions,
                'instructions_format' => $richText ? 'html' : 'text',
                'due_at' => $data['due_at'],
                'max_marks' => $maximum,
                'rubric' => $rubric,
                'rubric_version' => $rubricCleared ? $current->rubric_version + 1 : $current->rubric_version,
                'version' => $current->version + 1,
            ]);

            return ['rubric_cleared' => $rubricCleared];
        });
    }

    /** @param array<string, mixed> $input */
    public function rubric(User $actor, Assignment $assignment, array $input): void
    {
        DB::transaction(function () use ($actor, $assignment, $input) {
            WriteLock::acquire();
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
            WriteLock::acquire();
            $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $assignment->course);
            $input = DisplayTime::normalize($input, 'due_at');
            $data = Validator::make($input, ['user_id' => 'required|integer|exists:users,id', 'due_at' => 'required|date', 'reason' => 'required|string|max:1000'])->validate();
            $student = User::findOrFail($data['user_id']);
            $eligible = $student->role === 'student' && $assignment->course->enrollments()->where('user_id', $student->id)->exists();
            abort_unless($eligible, 422, 'Select a currently enrolled learner in this course.');
            if (! Carbon::parse($data['due_at'])->gt($this->deadline($assignment, $student->id))) {
                throw ValidationException::withMessages(['due_at' => 'An extension must be later than the current deadline.']);
            }
            DB::table('assignment_extensions')->insert($data + ['assignment_id' => $assignment->id, 'actor_id' => $actor->id, 'created_at' => now()]);
            Submission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->where('submitted_at', '<=', $data['due_at'])->update(['is_late' => false]);
        });
    }

    /** @param array<string, mixed> $input */
    public function grade(User $actor, Submission $submission, array $input): void
    {
        DB::transaction(function () use ($actor, $submission, $input) {
            WriteLock::acquire();
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
            WriteLock::acquire();
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
