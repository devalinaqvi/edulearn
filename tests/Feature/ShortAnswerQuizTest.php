<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShortAnswerQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_answers_require_staff_review_and_confirmed_result_release(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Assessment', 'description' => 'Online', 'status' => 'published']);
        $this->actingAs($teacher)->post(route('quizzes.store', $course), ['title' => 'Mixed quiz', 'instructions' => 'Respond', 'opens_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'closes_at' => now()->addMinutes(10)->format('Y-m-d H:i:s'), 'duration_minutes' => 5])->assertRedirect();
        $quiz = DB::table('quizzes')->first();
        $this->post(route('quizzes.author', $quiz->id), ['version' => 0, 'action' => 'question', 'prompt' => 'Choose', 'options' => ['A', 'B', 'C', 'D'], 'correct' => 1, 'points' => 5])->assertRedirect();
        $this->post(route('quizzes.author', $quiz->id), ['version' => 1, 'action' => 'question', 'type' => 'short', 'prompt' => 'Explain why', 'points' => 5])->assertRedirect();
        $this->post(route('quizzes.author', $quiz->id), ['version' => 2, 'action' => 'publish'])->assertRedirect();
        $this->actingAs($student)->post(route('courses.enroll', $course))->assertRedirect();
        $this->post(route('quizzes.start', $quiz->id))->assertRedirect();
        $this->get(route('quizzes.show', $quiz->id))->assertOk()->assertSee('Explain why')->assertSee('textarea', false)->assertDontSee('Correct answer');
        $this->post(route('quizzes.answer', $quiz->id), ['version' => 0, 'action' => 'submit', 'answers' => [0 => 1, 1 => 'My evidence']])->assertRedirect();
        $attempt = DB::table('quiz_attempts')->first();
        $this->assertSame('My evidence', json_decode($attempt->answers, true)[1]);
        $this->get(route('quizzes.show', $quiz->id))->assertOk()->assertSee('awaiting instructor publication')->assertDontSee('5 / 10');
        $publish = ['version' => 1, 'confirm' => 1, 'reason' => 'Reviewed'];
        $this->post(route('quiz-attempts.publish', $attempt->id), $publish)->assertForbidden();
        $this->actingAs($other)->get(route('quiz-attempts.review', $attempt->id))->assertForbidden();
        $this->actingAs($teacher)->get(route('quiz-attempts.review', $attempt->id))->assertOk()->assertSee('My evidence');
        $this->post(route('quiz-attempts.publish', $attempt->id), $publish)->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->post(route('quiz-attempts.publish', $attempt->id), $publish)->assertUnprocessable();
        $review = ['version' => 1, 'scores' => [1 => 2.75], 'feedback' => 'Reviewed feedback', 'reason' => 'Evidence reviewed'];
        $this->post(route('quiz-attempts.grade', $attempt->id), array_replace($review, ['scores' => [1 => 6]]))->assertSessionHasErrors('scores.1');
        $this->post(route('quiz-attempts.grade', $attempt->id), $review)->assertRedirect();
        $this->assertDatabaseHas('quiz_attempts', ['id' => $attempt->id, 'score' => 7.75]);
        $this->post(route('quiz-attempts.grade', $attempt->id), $review)->assertConflict();
        $this->post(route('quiz-attempts.publish', $attempt->id), ['version' => 2, 'reason' => 'Reviewed'])->assertSessionHasErrors('confirm');
        $this->post(route('quiz-attempts.publish', $attempt->id), array_replace($publish, ['version' => 2]))->assertRedirect();
        $this->post(route('quiz-attempts.publish', $attempt->id), array_replace($publish, ['version' => 2]))->assertRedirect();
        $this->assertDatabaseCount('quiz_assessment_changes', 2);
        $this->actingAs($student)->get(route('quizzes.show', $quiz->id))->assertOk()->assertSee('7.75 / 10')->assertSee('Reviewed feedback');
        $this->actingAs($teacher)->post(route('quiz-attempts.grade', $attempt->id), array_replace($review, ['version' => 2, 'scores' => [1 => 4], 'feedback' => 'Unreleased correction']))->assertRedirect();
        $this->actingAs($student)->get(route('quizzes.show', $quiz->id))->assertOk()->assertSee('7.75 / 10')->assertDontSee('Unreleased correction');
    }
}
