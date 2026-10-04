<?php

namespace App\Http\Controllers;

use App\Actions\QuizWorkflow;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Material;
use App\Services\QuizQuestionProvider;
use App\Services\SourceText;
use Illuminate\Contracts\View\View;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class QuizController extends Controller
{
    public function index(Course $course): View
    {
        Gate::authorize('view', $course);
        $manage = Gate::allows('manage', $course);
        $quizzes = DB::table('quizzes')->where('course_id', $course->id)->when(! $manage, fn ($query) => $query->where('status', 'published'))->orderByDesc('id')->paginate(20);

        return view('quizzes.index', compact('course', 'manage', 'quizzes'));
    }

    public function store(Request $request, Course $course, QuizWorkflow $workflow): RedirectResponse
    {
        $id = $workflow->create($request->user(), $course, $request->all());

        return redirect()->route('quizzes.show', $id)->with('status', 'Quiz draft created. Add questions, then publish.');
    }

    public function show(Request $request, int $quiz): View
    {
        $quiz = DB::table('quizzes')->find($quiz);
        abort_unless($quiz, 404);
        $course = Course::findOrFail($quiz->course_id);
        Gate::authorize('view', $course);
        $manage = Gate::allows('manage', $course);
        abort_unless($manage || $quiz->status === 'published', 404);
        $attempt = DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->where('user_id', $request->user()->id)->first();
        $questions = json_decode($quiz->questions, true);
        $maximum = array_sum(array_column($questions, 'points'));
        if (! $manage) {
            $questions = $attempt ? array_map(fn ($question) => array_diff_key($question, ['correct' => true]), $questions) : [];
            unset($quiz->questions);
        }
        $answers = $attempt ? json_decode($attempt->answers, true) : [];
        $expired = $attempt && now()->gte(Carbon::parse($attempt->deadline_at));
        $sources = $manage
            ? ['lessons' => Lesson::where('course_id', $course->id)->where('status', 'active')->orderBy('position')->get(['id', 'title']),
                'materials' => Material::where('course_id', $course->id)->where('status', 'active')->orderBy('id')->get(['id', 'title'])]
            : ['lessons' => collect(), 'materials' => collect()];
        $results = $manage ? DB::table('quiz_attempts')->join('users', 'users.id', '=', 'quiz_attempts.user_id')->where('quiz_id', $quiz->id)->select('quiz_attempts.*', 'users.name')->orderBy('users.name')->paginate(30) : null;

        return view('quizzes.show', compact('quiz', 'course', 'manage', 'attempt', 'questions', 'maximum', 'answers', 'expired', 'results', 'sources'));
    }

    public function review(Request $request, int $attempt)
    {
        $attempt = DB::table('quiz_attempts')->find($attempt);
        abort_unless($attempt, 404);
        $quiz = DB::table('quizzes')->find($attempt->quiz_id);
        Gate::authorize('manage', Course::findOrFail($quiz->course_id));
        $questions = json_decode($quiz->questions, true);
        $answers = json_decode($attempt->answers, true);
        $scores = json_decode($attempt->manual_scores ?? '[]', true);
        $history = DB::table('quiz_assessment_changes')->where('quiz_attempt_id', $attempt->id)->orderByDesc('id')->get();

        return view('quizzes.review', compact('quiz', 'attempt', 'questions', 'answers', 'scores', 'history'));
    }

    public function saveReview(Request $request, int $attempt, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->review($request->user(), $attempt, $request->all());

        return back()->with('status', 'Draft quiz review saved.');
    }

    public function publishResult(Request $request, int $attempt, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->review($request->user(), $attempt, $request->all(), true);

        return back()->with('status', 'Quiz result published.');
    }

    public function update(Request $request, int $quiz, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->updateDetails($request->user(), $quiz, $request->all());

        return back()->with('status', 'Quiz details updated. Published questions are unchanged.');
    }

    public function generate(Request $request, int $quiz, QuizWorkflow $workflow, QuizQuestionProvider $provider, SourceText $extractor): RedirectResponse
    {
        // The form offers one list of sources; split "lesson:3" back into the two fields.
        $input = $request->all();
        if (is_string($input['source_id'] ?? null) && str_contains($input['source_id'], ':')) {
            [$input['source_type'], $input['source_id']] = explode(':', $input['source_id'], 2);
        }

        try {
            $result = $workflow->generateQuestions($request->user(), $quiz, $input, $provider, $extractor);
        } catch (HttpExceptionInterface|RecordsNotFoundException $exception) {
            // Authorization, state and missing-record refusals keep their own status. Both of
            // these extend RuntimeException, so without this they would be reported as provider
            // trouble and answered with a redirect instead of a 403, 404 or 409.
            throw $exception;
        } catch (\RuntimeException $exception) {
            // Provider wording is written for an administrator; nothing internal is exposed.
            return back()->withErrors(['generation' => $exception->getMessage()]);
        }

        return back()->with('status', $result['added'] === 0
            ? 'No new questions were added: the response repeated questions this quiz already has.'
            : $result['added'].' draft question'.($result['added'] === 1 ? '' : 's').' added. Review every question and its answer before publishing; nothing is visible to learners until you publish.');
    }

    public function author(Request $request, int $quiz, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->author($request->user(), $quiz, $request->all());

        return back()->with('status', 'Quiz updated.');
    }

    public function start(Request $request, int $quiz, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->start($request->user(), $quiz);

        return redirect()->route('quizzes.show', $quiz);
    }

    public function answer(Request $request, int $quiz, QuizWorkflow $workflow): RedirectResponse
    {
        $workflow->answer($request->user(), $quiz, $request->all());

        return redirect()->route('quizzes.show', $quiz)->with('status', 'Attempt saved. If the deadline passed, only previously saved answers were scored.');
    }
}
