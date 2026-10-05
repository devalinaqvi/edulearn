<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterises what publication actually locks.
 *
 * Course publication is a visibility state: CoursePolicy::manage carries no status check, so it
 * never withdraws authoring rights. These tests pin that down so a future change cannot quietly
 * couple availability to immutability, and record the two genuine gaps that are easily mistaken
 * for a publication lock.
 */
class PublishedCourseEditingTest extends TestCase
{
    use RefreshDatabase;

    private function publishedCourse(User $teacher): Course
    {
        return Course::create(['code' => 'EL-PUB', 'instructor_id' => $teacher->id, 'title' => 'Published course', 'description' => 'Online', 'status' => 'published']);
    }

    public function test_lessons_stay_editable_after_the_course_is_published(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = $this->publishedCourse($teacher);
        $lesson = $course->lessons()->create(['title' => 'Original', 'body' => 'Original body', 'position' => 1]);

        $this->actingAs($teacher)->patch(route('lessons.update', $lesson), [
            'title' => 'Edited after publication',
            'body' => 'Edited body',
            'position' => 2,
        ])->assertRedirect();

        $lesson->refresh();
        $this->assertSame('Edited after publication', $lesson->title);
        $this->assertSame(2, $lesson->position);
        $this->assertSame('published', $course->fresh()->status, 'Editing content must not change the course state.');
    }

    public function test_the_edit_control_is_rendered_on_a_published_course_for_staff_only(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1]);
        $course->enrollments()->create(['user_id' => $student->id]);

        $this->actingAs($teacher)->get(route('courses.show', $course))->assertOk()->assertSee('Edit this lesson');
        $this->actingAs($student)->get(route('courses.show', $course))->assertOk()->assertDontSee('Edit this lesson');
    }

    public function test_publication_does_not_widen_who_may_edit(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $outsider = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $lesson = $course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1]);
        $course->enrollments()->create(['user_id' => $student->id]);
        $payload = ['title' => 'Hijacked', 'body' => 'Hijacked', 'position' => 1];

        $this->actingAs($outsider)->patch(route('lessons.update', $lesson), $payload)->assertForbidden();
        $this->actingAs($student)->patch(route('lessons.update', $lesson), $payload)->assertForbidden();

        $this->assertSame('Lesson', $lesson->fresh()->title);
    }

    public function test_a_deactivated_instructor_cannot_edit_a_published_course(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = $this->publishedCourse($teacher);
        $lesson = $course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1]);
        $teacher->forceFill(['is_active' => false])->save();

        $this->actingAs($teacher)->patch(route('lessons.update', $lesson), ['title' => 'X', 'body' => 'Y', 'position' => 1])
            ->assertRedirect(route('login'));

        $this->assertSame('Lesson', $lesson->fresh()->title);
    }

    public function test_editing_a_published_lesson_preserves_learner_progress(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $lesson = $course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1]);
        $course->enrollments()->create(['user_id' => $student->id]);
        $this->actingAs($student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();

        $this->actingAs($teacher)->patch(route('lessons.update', $lesson), ['title' => 'Revised', 'body' => 'Revised body', 'position' => 1])->assertRedirect();

        $this->assertDatabaseHas('lesson_completions', ['lesson_id' => $lesson->id, 'user_id' => $student->id]);
        $this->assertSame(100, $course->fresh()->progressFor($student));
    }

    public function test_quiz_authoring_is_gated_by_the_quiz_state_not_the_course_state(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = $this->publishedCourse($teacher);

        // A draft quiz inside an already published course accepts questions: the course's own
        // state is not consulted anywhere in the authoring path.
        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Quiz in a published course',
            'instructions' => 'Answer every question.',
            'opens_at' => DisplayTime::forInput(now()->addHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(2)),
            'duration_minutes' => 30,
        ])->assertRedirect();

        $quiz = DB::table('quizzes')->where('course_id', $course->id)->sole();
        $question = ['version' => $quiz->version, 'action' => 'question', 'type' => 'mcq', 'prompt' => 'Added after course publication?', 'points' => 5, 'options' => ['a', 'b', 'c', 'd'], 'correct' => 0];

        $this->post(route('quizzes.author', $quiz->id), $question)->assertRedirect();
        $this->assertCount(1, json_decode(DB::table('quizzes')->where('id', $quiz->id)->value('questions'), true));

        // Publishing the QUIZ is what freezes it, and that is a deliberate assessment-integrity
        // rule, asserted independently by QuizWorkflowTest.
        $this->post(route('quizzes.author', $quiz->id), ['version' => 1, 'action' => 'publish'])->assertRedirect();
        $this->assertSame('published', DB::table('quizzes')->where('id', $quiz->id)->value('status'));

        $this->post(route('quizzes.author', $quiz->id), ['version' => 2, 'action' => 'question', 'type' => 'mcq', 'prompt' => 'Late addition', 'points' => 5, 'options' => ['a', 'b', 'c', 'd'], 'correct' => 0])
            ->assertSessionHasErrors('conflict');
    }

    /** @return array{0: User, 1: User, 2: Course, 3: Assignment} */
    private function assignmentScenario(): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);
        $assignment = $course->assignments()->create([
            'title' => 'Original title',
            'instructions' => 'Original instructions',
            'due_at' => now()->addWeek(),
            'max_marks' => 100,
        ]);

        // version is a database default, which Eloquent does not read back on create().
        return [$teacher, $student, $course, $assignment->refresh()];
    }

    private function assignmentPayload(Assignment $assignment, array $overrides = []): array
    {
        return array_merge([
            'version' => $assignment->version,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'),
            'max_marks' => $assignment->max_marks,
        ], $overrides);
    }

    public function test_an_assignment_in_a_published_course_can_be_edited(): void
    {
        [$teacher, , $course, $assignment] = $this->assignmentScenario();

        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, [
            'title' => 'Edited after publication',
            'instructions' => 'Revised instructions',
            'due_at' => DisplayTime::forInput(now()->addWeeks(2)),
        ]))->assertRedirect();

        $assignment->refresh();
        $this->assertSame('Edited after publication', $assignment->title);
        $this->assertSame('Revised instructions', $assignment->instructions);
        $this->assertSame(1, $assignment->version);
        $this->assertSame('published', $course->fresh()->status);
    }

    public function test_total_marks_are_frozen_once_work_is_submitted_and_the_submission_survives(): void
    {
        [$teacher, $student, , $assignment] = $this->assignmentScenario();
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();

        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, ['max_marks' => 50]))
            ->assertSessionHasErrors('max_marks');

        $this->assertSame(100, $assignment->fresh()->max_marks);
        $this->assertDatabaseHas('submissions', ['assignment_id' => $assignment->id, 'user_id' => $student->id, 'body' => 'My answer']);
    }

    public function test_the_rest_of_an_assignment_stays_editable_after_work_is_submitted(): void
    {
        [$teacher, $student, , $assignment] = $this->assignmentScenario();
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();

        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, [
            'title' => 'Clarified title',
            'instructions' => 'Clarified instructions after submissions exist',
        ]))->assertRedirect();

        $this->assertSame('Clarified title', $assignment->fresh()->title);
        $this->assertDatabaseHas('submissions', ['assignment_id' => $assignment->id, 'body' => 'My answer']);
    }

    public function test_changing_the_total_clears_a_rubric_that_would_no_longer_add_up(): void
    {
        [$teacher, , , $assignment] = $this->assignmentScenario();
        $this->actingAs($teacher)->post(route('assignments.rubric', $assignment), [
            'version' => 0,
            'criteria' => [['label' => 'Analysis', 'max_marks' => 60], ['label' => 'Clarity', 'max_marks' => 40]],
        ])->assertRedirect();
        $this->assertNotNull($assignment->fresh()->rubric);

        $this->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment->fresh(), ['max_marks' => 80]))->assertRedirect();

        $assignment->refresh();
        $this->assertSame(80, $assignment->max_marks);
        $this->assertNull($assignment->rubric, 'A rubric that no longer totals the maximum must not be left inconsistent.');
    }

    public function test_a_rubric_that_still_adds_up_is_kept(): void
    {
        [$teacher, , , $assignment] = $this->assignmentScenario();
        $this->actingAs($teacher)->post(route('assignments.rubric', $assignment), [
            'version' => 0,
            'criteria' => [['label' => 'Analysis', 'max_marks' => 60], ['label' => 'Clarity', 'max_marks' => 40]],
        ])->assertRedirect();

        $this->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment->fresh(), ['title' => 'Same marks']))->assertRedirect();

        $this->assertCount(2, $assignment->fresh()->rubric);
    }

    public function test_a_stale_assignment_form_is_rejected(): void
    {
        [$teacher, , , $assignment] = $this->assignmentScenario();
        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, ['title' => 'First save']))->assertRedirect();

        $this->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, ['title' => 'Stale save']))->assertSessionHasErrors('conflict');

        $this->assertSame('First save', $assignment->fresh()->title);
    }

    public function test_only_authorized_staff_may_edit_an_assignment(): void
    {
        [, $student, , $assignment] = $this->assignmentScenario();
        $outsider = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($outsider)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, ['title' => 'Hijacked']))->assertForbidden();
        $this->actingAs($student)->patch(route('assignments.update', $assignment), $this->assignmentPayload($assignment, ['title' => 'Hijacked']))->assertForbidden();

        $this->assertSame('Original title', $assignment->fresh()->title);
    }

    public function test_a_published_quiz_keeps_editable_details_while_its_questions_stay_frozen(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Original quiz', 'instructions' => 'Original instructions',
            'opens_at' => DisplayTime::forInput(now()->subHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(2)),
            'duration_minutes' => 30,
        ])->assertRedirect();
        $quiz = DB::table('quizzes')->where('course_id', $course->id)->sole();
        $this->post(route('quizzes.author', $quiz->id), ['version' => 0, 'action' => 'question', 'type' => 'mcq', 'prompt' => 'Frozen question', 'points' => 5, 'options' => ['a', 'b', 'c', 'd'], 'correct' => 1]);
        $this->post(route('quizzes.author', $quiz->id), ['version' => 1, 'action' => 'publish'])->assertRedirect();

        $published = DB::table('quizzes')->find($quiz->id);
        $questionsBefore = $published->questions;

        $this->patch(route('quizzes.update', $quiz->id), [
            'version' => $published->version,
            'title' => 'Renamed after publication',
            'instructions' => 'Clarified instructions',
            'opens_at' => DisplayTime::forInput(now()->subHours(2)),
            'closes_at' => DisplayTime::forInput(now()->addDays(5)),
            'duration_minutes' => 45,
        ])->assertRedirect();

        $updated = DB::table('quizzes')->find($quiz->id);
        $this->assertSame('Renamed after publication', $updated->title);
        $this->assertSame(45, (int) $updated->duration_minutes);
        $this->assertSame('published', $updated->status);
        $this->assertSame($questionsBefore, $updated->questions, 'Editing details must never touch the question set.');
    }

    public function test_moving_the_window_does_not_change_a_deadline_a_learner_already_began_under(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Timed quiz', 'instructions' => 'Begin now.',
            'opens_at' => DisplayTime::forInput(now()->subHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(2)),
            'duration_minutes' => 30,
        ])->assertRedirect();
        $quiz = DB::table('quizzes')->where('course_id', $course->id)->sole();
        $this->post(route('quizzes.author', $quiz->id), ['version' => 0, 'action' => 'question', 'type' => 'mcq', 'prompt' => 'Q', 'points' => 5, 'options' => ['a', 'b', 'c', 'd'], 'correct' => 0]);
        $this->post(route('quizzes.author', $quiz->id), ['version' => 1, 'action' => 'publish'])->assertRedirect();

        $this->actingAs($student)->post(route('quizzes.start', $quiz->id))->assertRedirect();
        $deadlineBefore = DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->value('deadline_at');

        $this->actingAs($teacher)->patch(route('quizzes.update', $quiz->id), [
            'version' => DB::table('quizzes')->where('id', $quiz->id)->value('version'),
            'title' => 'Timed quiz', 'instructions' => 'Begin now.',
            'opens_at' => DisplayTime::forInput(now()->subHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(9)),
            'duration_minutes' => 120,
        ])->assertRedirect();

        $this->assertSame($deadlineBefore, DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->value('deadline_at'));
    }

    public function test_quiz_details_reject_an_inverted_window_a_stale_form_and_unauthorized_staff(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $outsider = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->publishedCourse($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Quiz', 'instructions' => 'Instructions',
            'opens_at' => DisplayTime::forInput(now()->addHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(2)),
            'duration_minutes' => 30,
        ])->assertRedirect();
        $quiz = DB::table('quizzes')->where('course_id', $course->id)->sole();

        $valid = [
            'version' => $quiz->version, 'title' => 'Quiz', 'instructions' => 'Instructions',
            'opens_at' => DisplayTime::forInput(now()->addHour()),
            'closes_at' => DisplayTime::forInput(now()->addDays(2)),
            'duration_minutes' => 30,
        ];

        $this->patch(route('quizzes.update', $quiz->id), ['closes_at' => DisplayTime::forInput(now()->subDay())] + $valid)
            ->assertSessionHasErrors('closes_at');

        $this->patch(route('quizzes.update', $quiz->id), ['version' => 99] + $valid)->assertSessionHasErrors('conflict');

        $this->actingAs($outsider)->patch(route('quizzes.update', $quiz->id), $valid)->assertForbidden();
        $this->actingAs($student)->patch(route('quizzes.update', $quiz->id), $valid)->assertForbidden();

        $this->assertSame('Quiz', DB::table('quizzes')->where('id', $quiz->id)->value('title'));
    }

    public function test_the_edit_panels_are_rendered_for_staff_on_published_content(): void
    {
        [$teacher, $student, , $assignment] = $this->assignmentScenario();

        $this->actingAs($teacher)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Edit this assignment');
        $this->actingAs($student)->get(route('assignments.show', $assignment))->assertOk()->assertDontSee('Edit this assignment');
    }
}
