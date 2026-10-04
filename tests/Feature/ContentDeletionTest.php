<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // Learners have no trash at all.
        $this->actingAs($student)->get(route('trash'))->assertOk()->assertDontSee('Lesson one');
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
