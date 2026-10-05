<?php

namespace App\Actions;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\DependencyAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Archival, restoration and permanent deletion of course content.
 *
 * Deletion is defended at four levels, and none of them is the hidden button:
 *   1. authorization, re-checked against fresh state inside the transaction;
 *   2. the shared write lock, so two staff cannot race the same decision;
 *   3. the dependency analyser, which reads the real foreign-key graph;
 *   4. the database's own constraints, which still refuse an unsafe delete if everything above
 *      were somehow wrong.
 *
 * When a record cannot be destroyed it is archived instead. Nothing is ever silently discarded,
 * and no constraint is ever disabled to make a delete succeed.
 */
class ContentLifecycle
{
    public function __construct(private DependencyAnalyzer $dependencies) {}

    /**
     * Archive or restore a lesson. Archived lessons leave the learner's view and stop counting
     * toward course progress, while every completion record already earned is retained.
     *
     * @param  array<string, mixed>  $input
     */
    public function setLessonArchived(User $actor, Lesson $lesson, array $input): string
    {
        return DB::transaction(function () use ($actor, $lesson, $input) {
            WriteLock::acquire();
            $current = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->course);

            $data = Validator::make($input, [
                'version' => 'required|integer|min:0',
                'action' => 'required|in:archive,restore',
                'reason' => 'nullable|string|max:1000',
            ])->validate();

            abort_if((int) $data['version'] !== $current->version, 409, 'This lesson changed. Reload before continuing.');

            $archiving = $data['action'] === 'archive';
            if ($archiving === $current->isArchived()) {
                return $archiving ? 'This lesson is already in Trash.' : 'This lesson is already active.';
            }

            $current->update([
                'status' => $archiving ? 'archived' : 'active',
                'archived_at' => $archiving ? now() : null,
                'archived_by' => $archiving ? $actor->id : null,
                'version' => $current->version + 1,
            ]);

            $this->record($actor, $current, $archiving ? 'archived' : 'restored', $data['reason'] ?? null);

            return $archiving
                ? 'Lesson moved to Trash. Learners can no longer open it and it no longer counts toward course progress. Existing progress records are preserved.'
                : 'Lesson restored. It is visible to learners again.';
        });
    }

    /**
     * Permanently delete a lesson, but only when nothing depends on it.
     *
     * @param  array<string, mixed>  $input
     * @return array{deleted: bool, message: string}
     */
    public function deleteLesson(User $actor, Lesson $lesson, array $input): array
    {
        return DB::transaction(function () use ($actor, $lesson, $input) {
            WriteLock::acquire();
            $current = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->course);

            $data = Validator::make($input, [
                'version' => 'required|integer|min:0',
                'confirm' => 'accepted',
                'reason' => 'nullable|string|max:1000',
            ])->validate();
            abort_if((int) $data['version'] !== $current->version, 409, 'This lesson changed. Reload before continuing.');

            $report = $this->dependencies->for('lessons', $current->id);
            if ($report['blocked']) {
                // Archive rather than refuse outright: the intent was to remove it from view.
                $current->update([
                    'status' => 'archived',
                    'archived_at' => $current->archived_at ?? now(),
                    'archived_by' => $current->archived_by ?? $actor->id,
                    'version' => $current->version + 1,
                ]);
                $this->record($actor, $current, 'deletion_blocked', $data['reason'] ?? null, $report['blockers']);

                return ['deleted' => false, 'message' => $report['summary']];
            }

            // Written before the delete so the subject is still readable, and in the same
            // transaction so the record and the deletion stand or fall together.
            $this->record($actor, $current, 'deleted', $data['reason'] ?? null);
            $current->delete();

            return ['deleted' => true, 'message' => 'Lesson permanently deleted. Nothing referred to it.'];
        });
    }

    /**
     * Permanently delete a course, but only when it holds no record of anything.
     *
     * In practice a course that has ever been taught cannot reach this path: lessons, enrolments,
     * assessments and announcements all hold it back. That is the intended outcome.
     *
     * @param  array<string, mixed>  $input
     * @return array{deleted: bool, message: string}
     */
    public function deleteCourse(User $actor, Course $course, array $input): array
    {
        return DB::transaction(function () use ($actor, $course, $input) {
            WriteLock::acquire();
            $current = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $fresh = $actor->fresh();
            Gate::forUser($fresh)->authorize('manage', $current);
            abort_unless($fresh->role === 'admin', 403, 'Only an administrator can delete a course.');

            $data = Validator::make($input, [
                'confirm' => 'accepted',
                'reason' => 'nullable|string|max:1000',
            ])->validate();

            $report = $this->dependencies->for('courses', $current->id);
            if ($report['blocked']) {
                $current->update(['status' => 'archived']);
                $this->record($actor, $current, 'deletion_blocked', $data['reason'] ?? null, $report['blockers']);

                return ['deleted' => false, 'message' => $report['summary']];
            }

            $this->record($actor, $current, 'deleted', $data['reason'] ?? null);
            $current->delete();

            return ['deleted' => true, 'message' => 'Course permanently deleted. It held no lessons, enrolments or assessments.'];
        });
    }

    /**
     * Write one line of the content's history.
     *
     * Called inside the caller's transaction, so an entry can never describe a change that was
     * rolled back, and a change can never happen unrecorded. Titles are copied rather than
     * referenced because the subject may be about to cease existing.
     *
     * @param  list<array{table: string, label: string, count: int}>  $preserved
     */
    private function record(User $actor, Lesson|Course $subject, string $action, ?string $reason, array $preserved = []): void
    {
        $course = $subject instanceof Course ? $subject : $subject->course;

        DB::table('content_lifecycle_changes')->insert([
            'actor_id' => $actor->id,
            'subject_type' => $subject instanceof Course ? 'course' : 'lesson',
            'subject_id' => $subject->id,
            'subject_title' => mb_substr($subject->title, 0, 255),
            'course_id' => $course?->id,
            'course_title' => $course ? mb_substr($course->title, 0, 255) : null,
            'action' => $action,
            'reason' => blank($reason) ? null : $reason,
            'preserved' => $preserved === [] ? null : json_encode($preserved),
            'created_at' => now(),
        ]);
    }

    /**
     * The recorded history of a piece of content, most recent first.
     *
     * @return Collection<int, object>
     */
    public function history(string $subjectType, int $subjectId)
    {
        return DB::table('content_lifecycle_changes')
            ->join('users', 'users.id', '=', 'content_lifecycle_changes.actor_id')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->orderByDesc('content_lifecycle_changes.id')
            ->select('content_lifecycle_changes.*', 'users.name as actor_name')
            ->get();
    }

    /**
     * Recent lifecycle activity across the courses a person may manage.
     *
     * @return Collection<int, object>
     */
    public function recentActivity(User $viewer, int $limit = 30)
    {
        // Narrowing only for instructors would leave every other role seeing everything, which
        // is how a learner briefly got the removal history of courses they were never in.
        if (! in_array($viewer->role, ['admin', 'instructor'], true)) {
            return collect();
        }

        return DB::table('content_lifecycle_changes')
            ->join('users', 'users.id', '=', 'content_lifecycle_changes.actor_id')
            ->when($viewer->role === 'instructor', fn ($query) => $query->whereIn(
                'content_lifecycle_changes.course_id',
                Course::query()->where(fn ($assigned) => $assigned->where('instructor_id', $viewer->id)
                    ->orWhereHas('coInstructors', fn ($instructor) => $instructor->where('users.id', $viewer->id)))->select('id'),
            ))
            ->orderByDesc('content_lifecycle_changes.id')->limit($limit)
            ->select('content_lifecycle_changes.*', 'users.name as actor_name')
            ->get();
    }

    /** What the interface should offer for a record, so it never shows an action that would fail. */
    public function report(string $table, int $id): array
    {
        return $this->dependencies->for($table, $id);
    }
}
