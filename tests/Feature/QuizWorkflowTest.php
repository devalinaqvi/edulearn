<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuizWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function quiz(bool $publish = true): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Quiz course', 'description' => 'Test', 'status' => 'published']);
        Enrollment::create(['course_id' => $course->id, 'user_id' => $student->id]);
        $this->actingAs($teacher)->post(route('quizzes.store', $course), ['title' => 'Knowledge check', 'instructions' => 'Choose one answer', 'opens_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'closes_at' => now()->addHour()->format('Y-m-d H:i:s'), 'duration_minutes' => 10])->assertRedirect();
        $quiz = DB::table('quizzes')->value('id');
        $this->post(route('quizzes.author', $quiz), ['version' => 0, 'action' => 'question', 'prompt' => 'Secret question prompt', 'options' => ['Option A', 'Option B', 'Option C', 'Option D'], 'correct' => 2, 'points' => 5])->assertRedirect();
        if ($publish) {
            $this->post(route('quizzes.author', $quiz), ['version' => 1, 'action' => 'publish'])->assertRedirect();
        }

        return [$teacher, $student, $course, $quiz];
    }

    public function test_drafts_are_private_and_published_questions_are_frozen(): void
    {
        [$teacher, $student, $course, $quiz] = $this->quiz(false);
        $this->actingAs($student)->get(route('quizzes.index', $course))->assertOk()->assertDontSee('Knowledge check');
        $this->get(route('quizzes.show', $quiz))->assertNotFound();
        $this->post(route('quizzes.start', $quiz))->assertNotFound();
        $this->post(route('quizzes.author', $quiz), ['version' => 1, 'action' => 'publish'])->assertForbidden();
        $this->actingAs($teacher)->get(route('quizzes.show', $quiz))->assertOk()->assertSee('Correct answer');
        $this->post(route('quizzes.author', $quiz), ['version' => 0, 'action' => 'publish'])->assertConflict();
        $this->post(route('quizzes.author', $quiz), ['version' => 1, 'action' => 'publish'])->assertRedirect();
        $this->post(route('quizzes.author', $quiz), ['version' => 2, 'action' => 'remove', 'index' => 0])->assertConflict();
        $this->actingAs($student)->get(route('quizzes.show', $quiz))->assertOk()->assertDontSee('Secret question prompt')->assertDontSee('Correct answer');
    }

    public function test_attempts_resume_save_and_score_only_server_answers_once(): void
    {
        [$teacher, $student, $course, $quiz] = $this->quiz();
        $this->actingAs($student)->post(route('quizzes.start', $quiz))->assertRedirect();
        $deadline = DB::table('quiz_attempts')->value('deadline_at');
        $this->travel(2)->minutes();
        $this->post(route('quizzes.start', $quiz))->assertRedirect();
        $this->assertDatabaseCount('quiz_attempts', 1);
        $this->assertSame($deadline, DB::table('quiz_attempts')->value('deadline_at'));
        $this->get(route('quizzes.show', $quiz))->assertOk()->assertSee('Secret question prompt')->assertDontSee('Correct answer')->assertDontSee('name="correct"', false);
        $this->post(route('quizzes.answer', $quiz), ['version' => 0, 'action' => 'save', 'answers' => [2]])->assertRedirect();
        $this->post(route('quizzes.answer', $quiz), ['version' => 0, 'action' => 'submit', 'answers' => [0]])->assertConflict();
        $this->post(route('quizzes.answer', $quiz), ['version' => 1, 'action' => 'submit', 'answers' => [2], 'score' => 999])->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['quiz_id' => $quiz, 'score' => 5, 'version' => 2]);
        $this->post(route('quizzes.answer', $quiz), ['version' => 1, 'action' => 'submit', 'answers' => [0]])->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['quiz_id' => $quiz, 'score' => 5, 'version' => 2]);
        $this->get(route('quizzes.show', $quiz))->assertOk()->assertSee('Your result')->assertSee('5 / 5');
        $this->actingAs($teacher)->get(route('quizzes.show', $quiz))->assertOk()->assertSee($student->name)->assertSee('5 / 5');
    }

    public function test_expired_attempt_ignores_late_answers_and_scheduler_finalizes_abandoned_work(): void
    {
        [$teacher, $student, $course, $quiz] = $this->quiz();
        $this->actingAs($student)->post(route('quizzes.start', $quiz))->assertRedirect();
        $this->post(route('quizzes.answer', $quiz), ['version' => 0, 'action' => 'save', 'answers' => [0]])->assertRedirect();
        $this->travel(10)->minutes();
        $this->get(route('quizzes.show', $quiz))->assertOk()->assertSee('Time has expired');
        $this->post(route('quizzes.answer', $quiz), ['version' => 1, 'action' => 'submit', 'answers' => [2]])->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['user_id' => $student->id, 'score' => 0]);
        $other = User::factory()->create(['role' => 'student']);
        Enrollment::create(['course_id' => $course->id, 'user_id' => $other->id]);
        $this->actingAs($other)->post(route('quizzes.start', $quiz))->assertRedirect();
        $this->post(route('quizzes.answer', $quiz), ['version' => 0, 'action' => 'save', 'answers' => [2]])->assertRedirect();
        $this->travel(11)->minutes();
        $this->artisan('quizzes:finalize')->expectsOutput('1 expired attempts finalized.')->assertSuccessful();
        $this->artisan('quizzes:finalize')->expectsOutput('0 expired attempts finalized.')->assertSuccessful();
        $this->assertDatabaseHas('quiz_attempts', ['user_id' => $other->id, 'score' => 5, 'version' => 2]);
    }

    public function test_availability_revoked_enrollment_and_other_users_cannot_access_attempts(): void
    {
        [$teacher, $student, $course, $quiz] = $this->quiz();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('quizzes.show', $quiz))->assertForbidden();
        $this->post(route('quizzes.start', $quiz))->assertForbidden();
        $this->post(route('quizzes.answer', $quiz), ['action' => 'submit'])->assertForbidden();
        $this->actingAs($student)->post(route('quizzes.answer', $quiz), ['action' => 'submit'])->assertUnprocessable();
        $this->post(route('quizzes.start', $quiz))->assertRedirect();
        Enrollment::where('user_id', $student->id)->delete();
        $this->post(route('quizzes.answer', $quiz), ['version' => 0, 'action' => 'submit', 'answers' => [2]])->assertForbidden();
        $this->get(route('quizzes.show', $quiz))->assertForbidden();
        $this->assertDatabaseHas('quiz_attempts', ['user_id' => $student->id, 'submitted_at' => null]);
        Enrollment::create(['course_id' => $course->id, 'user_id' => $stranger->id]);
        $this->travel(2)->hours();
        $this->actingAs($stranger)->post(route('quizzes.start', $quiz))->assertUnprocessable();
    }

    public function test_question_validation_removal_and_empty_publication(): void
    {
        [$teacher, $student, $course, $quiz] = $this->quiz(false);
        $this->post(route('quizzes.author', $quiz), ['version' => 1, 'action' => 'question', 'prompt' => 'Invalid', 'options' => ['same', 'same', 'C', 'D'], 'correct' => 9, 'points' => 0])->assertSessionHasErrors(['options.0', 'correct', 'points']);
        $this->post(route('quizzes.author', $quiz), ['version' => 1, 'action' => 'remove', 'index' => 0])->assertRedirect();
        $this->post(route('quizzes.author', $quiz), ['version' => 2, 'action' => 'publish'])->assertUnprocessable();
        $this->assertDatabaseHas('quizzes', ['id' => $quiz, 'status' => 'draft']);
        $this->assertSame([], json_decode(DB::table('quizzes')->where('id', $quiz)->value('questions'), true));
    }
}
