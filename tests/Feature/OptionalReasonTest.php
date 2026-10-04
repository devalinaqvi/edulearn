<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A reason is optional where it is editorial metadata, and required where it is the
 * justification for changing somebody's record.
 *
 * The distinction is deliberate: grade changes, released results, access changes and account
 * edits are written to audit tables that exist to answer "why", and an empty answer there would
 * make the trail worthless.
 */
class OptionalReasonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Course} */
    private function scenario(): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['code' => 'EL-RSN', 'instructor_id' => $teacher->id, 'title' => 'Reason course', 'description' => 'Online', 'status' => 'published']);
        $course->enrollments()->create(['user_id' => $student->id]);

        return [$teacher, $student, $course];
    }

    private function material(User $teacher, Course $course): int
    {
        Storage::fake('local');
        $this->actingAs($teacher)->post(route('materials.store', $course), [
            'title' => 'Reader', 'file' => UploadedFile::fake()->createWithContent('reader.txt', 'Readable text'),
        ])->assertRedirect();

        return (int) DB::table('materials')->where('course_id', $course->id)->value('id');
    }

    public function test_a_material_can_be_archived_without_giving_a_reason(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->material($teacher, $course);

        $version = DB::table('materials')->where('id', $id)->value('version');
        $this->post(route('materials.archive', $id), ['version' => $version, 'action' => 'archive'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('materials', ['id' => $id, 'status' => 'archived']);
    }

    public function test_an_empty_reason_is_stored_as_nothing_rather_than_an_empty_string(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->material($teacher, $course);

        $this->post(route('materials.replace', $id), [
            'version' => DB::table('materials')->where('id', $id)->value('version'), 'title' => 'Reader', 'reason' => '',
            'file' => UploadedFile::fake()->createWithContent('reader-v2.txt', 'Revised text'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull(DB::table('material_revisions')->where('material_id', $id)->value('reason'));
    }

    public function test_a_supplied_reason_is_still_recorded(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->material($teacher, $course);

        $this->post(route('materials.replace', $id), [
            'version' => DB::table('materials')->where('id', $id)->value('version'), 'title' => 'Reader', 'reason' => 'Corrected a factual error on page two.',
            'file' => UploadedFile::fake()->createWithContent('reader-v2.txt', 'Revised text'),
        ])->assertRedirect();

        $this->assertSame('Corrected a factual error on page two.', DB::table('material_revisions')->where('material_id', $id)->value('reason'));
    }

    public function test_a_lesson_can_be_archived_without_a_reason(): void
    {
        [$teacher, , $course] = $this->scenario();
        $lesson = $course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1])->refresh();

        $this->actingAs($teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('archived', $lesson->fresh()->status);
    }

    public function test_grading_still_demands_a_reason(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $assignment = $course->assignments()->create(['title' => 'Work', 'instructions' => 'Do it', 'due_at' => now()->addWeek(), 'max_marks' => 10]);
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();
        $submission = DB::table('submissions')->where('assignment_id', $assignment->id)->sole();

        $this->actingAs($teacher)->patch(route('submissions.grade', $submission->id), ['version' => 0, 'grade' => 8])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'status' => 'submitted']);
    }

    public function test_changing_course_access_still_demands_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, $student, $course] = $this->scenario();

        $this->actingAs($admin)->post(route('admin.access.change', $course), ['email' => $student->email, 'action' => 'remove'])
            ->assertSessionHasErrors('reason');
    }

    public function test_the_optional_reason_is_collapsed_by_default_in_the_interface(): void
    {
        [$teacher, , $course] = $this->scenario();
        $this->material($teacher, $course);

        $response = $this->get(route('courses.show', $course))->assertOk();
        $response->assertSee('reason-optional', false)->assertSee('Add a reason (optional)');
        // A <details> without the open attribute starts closed, so nothing is prefilled or shown.
        $this->assertStringNotContainsString('<details class="reason-optional" open', $response->getContent());
    }
}
