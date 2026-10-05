<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deletion must never take evidence of a learner's work with it.
 *
 * A lesson nothing refers to can be destroyed. A lesson anything refers to is archived instead,
 * and the person is told which records are being preserved rather than shown a database error.
 */
class ContentDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Course, 3: Lesson} */
    private function scenario(string $status = 'published'): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['code' => 'EL-DEL', 'instructor_id' => $teacher->id, 'title' => 'Deletion course', 'description' => 'Online', 'status' => $status]);
        $lesson = $course->lessons()->create(['title' => 'Lesson one', 'body' => 'Body', 'position' => 1]);
        $course->enrollments()->create(['user_id' => $student->id]);

        return [$teacher, $student, $course, $lesson->refresh()];
    }

    public function test_a_lesson_nothing_depends_on_is_permanently_deleted(): void
    {
        [$teacher, , , $lesson] = $this->scenario();

        $this->actingAs($teacher)->delete(route('lessons.destroy', $lesson), ['version' => $lesson->version, 'confirm' => '1'])
            ->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
    }

    public function test_a_lesson_with_learner_progress_is_archived_instead_and_the_reason_is_explained(): void
    {
        [$teacher, $student, , $lesson] = $this->scenario();
        $this->actingAs($student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();

        $this->actingAs($teacher)->delete(route('lessons.destroy', $lesson), ['version' => $lesson->version, 'confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'learner progress records') && str_contains($message, 'Trash'));

        // Preserved, not destroyed, and no raw database error reached the person.
        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'status' => 'archived']);
        $this->assertDatabaseHas('lesson_completions', ['lesson_id' => $lesson->id, 'user_id' => $student->id]);
    }

    public function test_archiving_withdraws_a_lesson_from_learners_without_losing_their_record(): void
    {
        [$teacher, $student, $course, $lesson] = $this->scenario();
        $this->actingAs($student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();
        $this->assertSame(100, $course->fresh()->progressFor($student));

        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();

        $this->actingAs($student)->get(route('courses.show', $course))->assertOk()->assertDontSee('Lesson one');
        $this->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertNotFound();
        $this->assertDatabaseHas('lesson_completions', ['lesson_id' => $lesson->id]);

        // No lessons remain in the course, so progress is zero rather than a division by zero.
        $this->assertSame(0, $course->fresh()->progressFor($student));
    }

    public function test_an_archived_lesson_can_be_restored(): void
    {
        [$teacher, $student, $course, $lesson] = $this->scenario();
        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();

        $this->post(route('lessons.archive', $lesson), ['version' => $lesson->fresh()->version, 'action' => 'restore'])->assertRedirect();

        $this->assertSame('active', $lesson->fresh()->status);
        $this->actingAs($student)->get(route('courses.show', $course))->assertOk()->assertSee('Lesson one');
    }

    public function test_archiving_is_idempotent_and_rejects_a_stale_form(): void
    {
        [$teacher, , , $lesson] = $this->scenario();
        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => 0, 'action' => 'archive'])->assertRedirect();

        // Same version replayed: the record has moved on.
        $this->post(route('lessons.archive', $lesson), ['version' => 0, 'action' => 'archive'])->assertStatus(409);

        $this->post(route('lessons.archive', $lesson), ['version' => $lesson->fresh()->version, 'action' => 'archive'])
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'already in Trash'));
    }

    public function test_only_authorized_staff_may_archive_or_delete_a_lesson(): void
    {
        [, $student, , $lesson] = $this->scenario();
        $outsider = User::factory()->create(['role' => 'instructor']);

        foreach ([$outsider, $student] as $actor) {
            $this->actingAs($actor)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertForbidden();
            $this->delete(route('lessons.destroy', $lesson), ['version' => $lesson->version, 'confirm' => '1'])->assertForbidden();
        }

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'status' => 'active']);
    }

    public function test_permanent_deletion_requires_explicit_confirmation(): void
    {
        [$teacher, , , $lesson] = $this->scenario();

        $this->actingAs($teacher)->delete(route('lessons.destroy', $lesson), ['version' => $lesson->version])
            ->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
    }

    public function test_a_course_holding_records_is_archived_rather_than_destroyed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, , $course] = $this->scenario();

        $this->actingAs($admin)->delete(route('courses.destroy', $course), ['confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'depend on this record'));

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'status' => 'archived']);
        $this->assertDatabaseHas('lessons', ['course_id' => $course->id]);
        $this->assertDatabaseHas('enrollments', ['course_id' => $course->id]);
    }

    public function test_an_empty_course_can_be_permanently_deleted_by_an_administrator(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-VOID', 'instructor_id' => $teacher->id, 'title' => 'Empty course', 'description' => 'Online', 'status' => 'draft']);

        $this->actingAs($admin)->delete(route('courses.destroy', $course), ['confirm' => '1'])->assertRedirect(route('courses.index'));

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }

    public function test_an_instructor_cannot_delete_a_course_even_when_it_is_empty(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-VOID2', 'instructor_id' => $teacher->id, 'title' => 'Empty course', 'description' => 'Online', 'status' => 'draft']);

        $this->actingAs($teacher)->delete(route('courses.destroy', $course), ['confirm' => '1'])->assertForbidden();

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
    }

    public function test_trash_lists_archived_content_scoped_to_the_viewer(): void
    {
        [$teacher, $student, , $lesson] = $this->scenario();
        $outsider = User::factory()->create(['role' => 'instructor']);
        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();

        $this->get(route('trash'))->assertOk()->assertSee('Lesson one')->assertSee('Delete permanently');

        // Another instructor's trash must not contain it.
        $this->actingAs($outsider)->get(route('trash'))->assertOk()->assertDontSee('Lesson one');

        // Learners have no trash at all, and no view of its history.
        $this->actingAs($student)->get(route('trash'))->assertForbidden();
    }

    public function test_archiving_and_restoring_are_recorded_with_the_actor_and_reason(): void
    {
        [$teacher, , , $lesson] = $this->scenario();

        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), [
            'version' => $lesson->version, 'action' => 'archive', 'reason' => 'Superseded by the revised unit.',
        ])->assertRedirect();
        $this->post(route('lessons.archive', $lesson), ['version' => $lesson->fresh()->version, 'action' => 'restore'])->assertRedirect();

        $entries = DB::table('content_lifecycle_changes')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('archived', $entries[0]->action);
        $this->assertSame($teacher->id, (int) $entries[0]->actor_id);
        $this->assertSame('Superseded by the revised unit.', $entries[0]->reason);
        $this->assertSame('Lesson one', $entries[0]->subject_title);
        $this->assertSame('restored', $entries[1]->action);
        $this->assertNull($entries[1]->reason, 'An omitted reason is stored as nothing, not an empty string.');
    }

    public function test_the_record_of_a_permanent_deletion_outlives_the_record_it_describes(): void
    {
        [$teacher, , , $lesson] = $this->scenario();
        $lessonId = $lesson->id;

        $this->actingAs($teacher)->delete(route('lessons.destroy', $lesson), [
            'version' => $lesson->version, 'confirm' => '1', 'reason' => 'Duplicated by mistake.',
        ])->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $lessonId]);

        // The whole point: the subject is gone and the entry is still legible.
        $entry = DB::table('content_lifecycle_changes')->where('action', 'deleted')->sole();
        $this->assertSame('lesson', $entry->subject_type);
        $this->assertSame($lessonId, (int) $entry->subject_id);
        $this->assertSame('Lesson one', $entry->subject_title);
        $this->assertSame('Duplicated by mistake.', $entry->reason);
    }

    public function test_a_blocked_deletion_records_what_was_preserved(): void
    {
        [$teacher, $student, , $lesson] = $this->scenario();
        $this->actingAs($student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();

        $this->actingAs($teacher)->delete(route('lessons.destroy', $lesson), ['version' => $lesson->version, 'confirm' => '1'])->assertRedirect();

        $entry = DB::table('content_lifecycle_changes')->where('action', 'deletion_blocked')->sole();
        $preserved = json_decode($entry->preserved, true);
        $this->assertNotEmpty($preserved);
        $this->assertSame('lesson_completions', $preserved[0]['table']);
        $this->assertSame(1, $preserved[0]['count']);
    }

    public function test_deleting_a_course_leaves_a_record_that_does_not_itself_block_the_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-AUDIT', 'instructor_id' => $teacher->id, 'title' => 'Audited course', 'description' => 'Online', 'status' => 'draft']);
        $courseId = $course->id;

        // Archive first, so a lifecycle row already references the course when it is deleted.
        $this->actingAs($admin)->delete(route('courses.destroy', $course), ['confirm' => '1', 'reason' => 'Created in error.'])
            ->assertRedirect(route('courses.index'));

        $this->assertDatabaseMissing('courses', ['id' => $courseId]);

        $entry = DB::table('content_lifecycle_changes')->where('subject_type', 'course')->sole();
        $this->assertSame('deleted', $entry->action);
        $this->assertSame('Audited course', $entry->subject_title);
        $this->assertSame('Created in error.', $entry->reason);
        // course_id nulls rather than restricting, or the audit row would have blocked the delete.
        $this->assertNull($entry->course_id);
    }

    public function test_a_prior_history_entry_never_prevents_a_course_from_being_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-HIST', 'instructor_id' => $teacher->id, 'title' => 'Historied course', 'description' => 'Online', 'status' => 'draft']);
        $lesson = $course->lessons()->create(['title' => 'Temp', 'body' => 'Body', 'position' => 1])->refresh();

        // Generate history against the course, then clear the real blocker.
        $this->actingAs($admin)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();
        $this->delete(route('lessons.destroy', $lesson), ['version' => $lesson->fresh()->version, 'confirm' => '1'])->assertRedirect();
        $this->assertGreaterThan(0, DB::table('content_lifecycle_changes')->where('course_id', $course->id)->count());

        $this->delete(route('courses.destroy', $course), ['confirm' => '1'])->assertRedirect(route('courses.index'));

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertGreaterThan(0, DB::table('content_lifecycle_changes')->count(), 'History survives its course.');
    }

    public function test_the_removal_history_is_shown_in_trash_and_scoped_to_the_viewer(): void
    {
        [$teacher, , , $lesson] = $this->scenario();
        $outsider = User::factory()->create(['role' => 'instructor']);
        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), [
            'version' => $lesson->version, 'action' => 'archive', 'reason' => 'Retired for this term.',
        ])->assertRedirect();

        $this->get(route('trash'))->assertOk()
            ->assertSee('Removal history')
            ->assertSee('Retired for this term.')
            ->assertSee($teacher->name);

        $this->actingAs($outsider)->get(route('trash'))->assertOk()->assertDontSee('Retired for this term.');
    }

    public function test_trash_hides_permanent_deletion_when_records_depend_on_the_lesson(): void
    {
        [$teacher, $student, , $lesson] = $this->scenario();
        $this->actingAs($student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();
        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->fresh()->version, 'action' => 'archive'])->assertRedirect();

        $this->get(route('trash'))->assertOk()
            ->assertDontSee('Delete permanently')
            ->assertSee('learner progress records');
    }
}
