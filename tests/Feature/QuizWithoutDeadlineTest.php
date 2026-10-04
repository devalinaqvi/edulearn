<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A quiz may carry both window bounds, either one, or neither.
 *
 * Absence is represented by a null column rather than a sentinel date, so "no deadline" cannot
 * quietly turn into a deadline when some far-future date arrives.
 */
class QuizWithoutDeadlineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Course} */
    private function scenario(): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['code' => 'EL-ND', 'instructor_id' => $teacher->id, 'title' => 'No deadline course', 'description' => 'Online', 'status' => 'published']);
        $course->enrollments()->create(['user_id' => $student->id]);

        return [$teacher, $student, $course];
    }

    private function createQuiz(User $teacher, Course $course, array $window): int
    {
        $this->actingAs($teacher)->post(route('quizzes.store', $course), $window + [
            'title' => 'Open practice quiz', 'instructions' => 'Answer when ready.', 'duration_minutes' => 20,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return (int) DB::table('quizzes')->where('course_id', $course->id)->latest('id')->value('id');
    }

    private function publish(int $quizId): void
    {
        $this->post(route('quizzes.author', $quizId), ['version' => 0, 'action' => 'question', 'type' => 'mcq', 'prompt' => 'Q', 'points' => 5, 'options' => ['a', 'b', 'c', 'd'], 'correct' => 0])->assertRedirect();
        $this->post(route('quizzes.author', $quizId), ['version' => 1, 'action' => 'publish'])->assertRedirect();
    }

    public function test_a_quiz_can_be_created_with_no_window_at_all(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);

        $quiz = DB::table('quizzes')->find($id);
        $this->assertNull($quiz->opens_at);
        $this->assertNull($quiz->closes_at);
    }

    public function test_each_window_combination_is_accepted(): void
    {
        [$teacher, , $course] = $this->scenario();

        $both = DB::table('quizzes')->find($this->createQuiz($teacher, $course, ['opens_at' => DisplayTime::forInput(now()->addHour()), 'closes_at' => DisplayTime::forInput(now()->addDay())]));
        $openOnly = DB::table('quizzes')->find($this->createQuiz($teacher, $course, ['opens_at' => DisplayTime::forInput(now()->addHour()), 'closes_at' => '']));
        $closeOnly = DB::table('quizzes')->find($this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => DisplayTime::forInput(now()->addDay())]));

        $this->assertNotNull($both->opens_at);
        $this->assertNotNull($both->closes_at);
        $this->assertNotNull($openOnly->opens_at);
        $this->assertNull($openOnly->closes_at);
        $this->assertNull($closeOnly->opens_at);
        $this->assertNotNull($closeOnly->closes_at);
    }

    public function test_a_quiz_with_no_opening_time_is_available_as_soon_as_it_is_published(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);
        $this->publish($id);

        $this->actingAs($student)->post(route('quizzes.start', $id))->assertRedirect();

        $this->assertDatabaseHas('quiz_attempts', ['quiz_id' => $id, 'user_id' => $student->id]);
    }

    public function test_an_attempt_is_still_time_limited_by_its_duration_when_the_quiz_never_closes(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);
        $this->publish($id);

        $this->actingAs($student)->post(route('quizzes.start', $id))->assertRedirect();

        $attempt = DB::table('quiz_attempts')->where('quiz_id', $id)->sole();
        $this->assertNotNull($attempt->deadline_at, 'An unbounded quiz must still bound the sitting.');
        $this->assertEqualsWithDelta(20 * 60, now()->diffInSeconds($attempt->deadline_at, false), 90);
    }

    public function test_an_opening_time_is_still_enforced_when_there_is_no_closing_time(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => DisplayTime::forInput(now()->addDay()), 'closes_at' => '']);
        $this->publish($id);

        $this->actingAs($student)->post(route('quizzes.start', $id))->assertStatus(422);
        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_results_may_be_released_once_the_attempt_is_finalized_when_nothing_closes(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);
        $this->publish($id);

        $this->actingAs($student)->post(route('quizzes.start', $id))->assertRedirect();
        $this->post(route('quizzes.answer', $id), ['version' => 0, 'action' => 'submit', 'answers' => [0 => 0]])->assertRedirect();
        $attempt = DB::table('quiz_attempts')->where('quiz_id', $id)->sole();
        $this->assertNotNull($attempt->submitted_at);

        // With a closing time this would be refused until the quiz closed; without one the
        // learner's own finalized attempt is the gate.
        $this->actingAs($teacher)->post(route('quiz-attempts.publish', $attempt->id), [
            'version' => $attempt->version, 'confirm' => '1', 'reason' => 'Released after the attempt was finalized.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('quiz_attempts')->where('id', $attempt->id)->value('result_published_at'));
    }

    public function test_results_are_still_withheld_until_a_declared_closing_time_passes(): void
    {
        [$teacher, $student, $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => DisplayTime::forInput(now()->addDay())]);
        $this->publish($id);

        $this->actingAs($student)->post(route('quizzes.start', $id))->assertRedirect();
        $this->post(route('quizzes.answer', $id), ['version' => 0, 'action' => 'submit', 'answers' => [0 => 0]])->assertRedirect();
        $attempt = DB::table('quiz_attempts')->where('quiz_id', $id)->sole();

        $this->actingAs($teacher)->post(route('quiz-attempts.publish', $attempt->id), [
            'version' => $attempt->version, 'confirm' => '1', 'reason' => 'Too early.',
        ])->assertStatus(422);
    }

    public function test_an_incoherent_window_is_still_refused(): void
    {
        [$teacher, , $course] = $this->scenario();

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Backwards', 'instructions' => 'x', 'duration_minutes' => 10,
            'opens_at' => DisplayTime::forInput(now()->addDays(2)),
            'closes_at' => DisplayTime::forInput(now()->addDay()),
        ])->assertSessionHasErrors('closes_at');

        $this->post(route('quizzes.store', $course), [
            'title' => 'Already closed', 'instructions' => 'x', 'duration_minutes' => 10,
            'opens_at' => '', 'closes_at' => DisplayTime::forInput(now()->subDay()),
        ])->assertSessionHasErrors('closes_at');
    }

    public function test_a_deadline_free_quiz_can_be_edited_and_given_a_deadline_later(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);
        $this->publish($id);
        $version = DB::table('quizzes')->where('id', $id)->value('version');

        $this->patch(route('quizzes.update', $id), [
            'version' => $version, 'title' => 'Now bounded', 'instructions' => 'Answer when ready.',
            'opens_at' => '', 'closes_at' => DisplayTime::forInput(now()->addWeek()), 'duration_minutes' => 20,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNotNull(DB::table('quizzes')->where('id', $id)->value('closes_at'));
    }

    public function test_the_interface_names_the_absence_of_a_deadline(): void
    {
        [$teacher, , $course] = $this->scenario();
        $id = $this->createQuiz($teacher, $course, ['opens_at' => '', 'closes_at' => '']);
        $this->publish($id);

        $this->get(route('quizzes.show', $id))->assertOk()->assertSee('no closing deadline');
        $this->get(route('quizzes.index', $course))->assertOk()->assertSee('no deadline');
    }
}
