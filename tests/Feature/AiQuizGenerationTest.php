<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AI-written questions are drafts for a person to check, never published content.
 *
 * External inference is faked throughout: these tests assert how the application treats a
 * response, including the many shapes a model gets wrong, not that any provider works.
 */
class AiQuizGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Course $course;

    private Lesson $lesson;

    private int $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::create(['code' => 'EL-AIQ', 'instructor_id' => $this->teacher->id, 'title' => 'AI quiz course', 'description' => 'Online', 'status' => 'published']);
        $this->lesson = $this->course->lessons()->create(['title' => 'Source lesson', 'body' => 'Authorization must always be enforced on the server, never only in the browser.', 'position' => 1]);

        // id is guarded on the model, so it is forced the same way AiAdminController does.
        (new AiConfiguration)->forceFill(['id' => 1, 'enabled' => true, 'provider' => 'openrouter', 'model' => 'free/model', 'api_key' => 'test-key', 'daily_limit' => 10, 'max_input_chars' => 12000, 'max_output_tokens' => 1200, 'require_zero_retention' => true, 'version' => 1])->save();

        $this->actingAs($this->teacher)->post(route('quizzes.store', $this->course), [
            'title' => 'Generated quiz', 'instructions' => 'Check your understanding.', 'duration_minutes' => 20,
            'opens_at' => '', 'closes_at' => '',
        ])->assertRedirect();
        $this->quiz = (int) DB::table('quizzes')->where('course_id', $this->course->id)->value('id');
    }

    private function providerReturns(string $content): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]])]);
    }

    private function questionsJson(int $count = 3, string $prefix = 'Question'): string
    {
        $questions = [];
        for ($i = 1; $i <= $count; $i++) {
            $questions[] = ['prompt' => $prefix.' '.$i, 'options' => ['A'.$i, 'B'.$i, 'C'.$i, 'D'.$i], 'correct' => 1, 'explanation' => 'Because.'];
        }

        return json_encode(['questions' => $questions]);
    }

    private function generate(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->teacher)->post(route('quizzes.generate', $this->quiz), array_merge([
            'version' => DB::table('quizzes')->where('id', $this->quiz)->value('version'),
            'source_id' => 'lesson:'.$this->lesson->id,
            'count' => 3,
            'difficulty' => 'intermediate',
        ], $overrides));
    }

    private function storedQuestions(): array
    {
        return json_decode(DB::table('quizzes')->where('id', $this->quiz)->value('questions'), true);
    }

    public function test_generated_questions_are_added_as_drafts_and_the_quiz_stays_unpublished(): void
    {
        $this->providerReturns($this->questionsJson());

        $this->generate()->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, '3 draft questions'));

        $this->assertCount(3, $this->storedQuestions());
        $this->assertSame('draft', DB::table('quizzes')->where('id', $this->quiz)->value('status'));
    }

    public function test_generated_questions_are_invisible_to_learners_until_the_quiz_is_published(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->course->enrollments()->create(['user_id' => $student->id]);
        $this->providerReturns($this->questionsJson());
        $this->generate()->assertRedirect();

        $this->actingAs($student)->get(route('quizzes.show', $this->quiz))->assertNotFound();
        $this->get(route('quizzes.index', $this->course))->assertOk()->assertDontSee('Generated quiz');
    }

    public function test_a_published_quiz_refuses_generated_questions(): void
    {
        $this->providerReturns($this->questionsJson(1));
        $this->generate(['count' => 1])->assertRedirect();
        $version = DB::table('quizzes')->where('id', $this->quiz)->value('version');
        $this->post(route('quizzes.author', $this->quiz), ['version' => $version, 'action' => 'publish'])->assertRedirect();

        $this->providerReturns($this->questionsJson(1, 'Late'));
        $this->generate(['count' => 1])->assertStatus(409);

        $this->assertCount(1, $this->storedQuestions());
    }

    public function test_json_wrapped_in_prose_and_fences_is_still_read(): void
    {
        $this->providerReturns("Certainly! Here are your questions:\n```json\n".$this->questionsJson(2)."\n```\nLet me know if you need more.");

        $this->generate(['count' => 2])->assertRedirect();

        $this->assertCount(2, $this->storedQuestions());
    }

    public function test_malformed_questions_are_discarded_rather_than_guessed_at(): void
    {
        $this->providerReturns(json_encode(['questions' => [
            ['prompt' => 'Good one', 'options' => ['A', 'B', 'C', 'D'], 'correct' => 2],
            ['prompt' => 'Only three options', 'options' => ['A', 'B', 'C'], 'correct' => 0],
            ['prompt' => 'No answer given', 'options' => ['A', 'B', 'C', 'D']],
            ['prompt' => 'Answer out of range', 'options' => ['A', 'B', 'C', 'D'], 'correct' => 9],
            ['prompt' => 'Duplicate options', 'options' => ['A', 'A', 'B', 'C'], 'correct' => 0],
            ['options' => ['A', 'B', 'C', 'D'], 'correct' => 0],
        ]]));

        $this->generate(['count' => 6])->assertRedirect();

        $questions = $this->storedQuestions();
        $this->assertCount(1, $questions);
        $this->assertSame('Good one', $questions[0]['prompt']);
    }

    public function test_a_repeated_question_is_not_added_twice(): void
    {
        $this->providerReturns(json_encode(['questions' => [
            ['prompt' => 'Same question', 'options' => ['A', 'B', 'C', 'D'], 'correct' => 0],
            ['prompt' => 'same question', 'options' => ['W', 'X', 'Y', 'Z'], 'correct' => 1],
        ]]));

        $this->generate(['count' => 2])->assertRedirect();

        $this->assertCount(1, $this->storedQuestions());
    }

    public function test_an_unusable_response_reports_a_readable_problem_and_adds_nothing(): void
    {
        foreach (['', 'I cannot help with that.', '{"questions":[]}'] as $content) {
            $this->providerReturns($content);
            $this->generate()->assertSessionHasErrors('generation');
        }

        $this->assertCount(0, $this->storedQuestions());
    }

    public function test_provider_failures_are_translated_rather_than_exposed(): void
    {
        // A sequence, not repeated fake() calls: those merge, so the first stub would keep winning.
        $body = ['error' => ['message' => 'internal provider detail: account 12345']];
        Http::fakeSequence('openrouter.ai/*')
            ->push($body, 429)
            ->push($body, 401)
            ->push($body, 500);

        foreach (['rate-limited', 'credential', 'could not complete'] as $expected) {
            $response = $this->generate();
            $response->assertSessionHasErrors('generation');
            $message = $response->getSession()->get('errors')->first('generation');
            $this->assertStringContainsString($expected, $message);
            $this->assertStringNotContainsString('account 12345', $message, 'Provider internals must never reach the screen.');
        }

        $this->assertCount(0, $this->storedQuestions());
    }

    public function test_a_source_from_another_course_is_refused(): void
    {
        $otherCourse = Course::create(['code' => 'EL-OTHER', 'instructor_id' => $this->teacher->id, 'title' => 'Other', 'description' => 'Online', 'status' => 'published']);
        $foreign = $otherCourse->lessons()->create(['title' => 'Foreign', 'body' => 'Not part of this course.', 'position' => 1]);
        $this->providerReturns($this->questionsJson());

        $this->generate(['source_id' => 'lesson:'.$foreign->id])->assertNotFound();

        $this->assertCount(0, $this->storedQuestions());
    }

    public function test_only_course_staff_may_generate_questions(): void
    {
        $outsider = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $this->course->enrollments()->create(['user_id' => $student->id]);
        $this->providerReturns($this->questionsJson());

        foreach ([$outsider, $student] as $actor) {
            $this->actingAs($actor)->post(route('quizzes.generate', $this->quiz), [
                'version' => 0, 'source_id' => 'lesson:'.$this->lesson->id, 'count' => 3, 'difficulty' => 'intermediate',
            ])->assertForbidden();
        }

        $this->assertCount(0, $this->storedQuestions());
    }

    public function test_generation_is_refused_when_ai_is_disabled(): void
    {
        AiConfiguration::findOrFail(1)->update(['enabled' => false]);
        Http::fake();

        $this->generate()->assertSessionHasErrors('generation');

        $this->assertCount(0, $this->storedQuestions());
        Http::assertNothingSent();
    }

    public function test_the_mock_provider_labels_its_output_and_calls_nothing(): void
    {
        AiConfiguration::findOrFail(1)->update(['provider' => 'mock']);
        Http::fake();

        $this->generate(['count' => 2])->assertRedirect();

        $questions = $this->storedQuestions();
        $this->assertCount(2, $questions);
        $this->assertStringContainsString('DEVELOPMENT MOCK', $questions[0]['prompt']);
        Http::assertNothingSent();
    }

    public function test_usage_is_recorded_for_an_administrator_to_see(): void
    {
        $this->providerReturns($this->questionsJson(2));
        $this->generate(['count' => 2])->assertRedirect();

        $this->assertDatabaseHas('ai_usage_events', ['status' => 'completed', 'detail' => '2 draft questions generated']);
    }
}
