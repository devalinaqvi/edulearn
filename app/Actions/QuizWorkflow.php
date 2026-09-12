<?php

namespace App\Actions;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use stdClass;

class QuizWorkflow
{
    /** @param array<string, mixed> $input */
    public function create(User $actor, Course $course, array $input): int
    {
        return DB::transaction(function () use ($actor, $course, $input) {
            $this->lock();
            Gate::forUser($actor->fresh())->authorize('manage', $course->fresh());
            $data = Validator::make($input, ['title' => 'required|string|max:160', 'instructions' => 'required|string|max:10000', 'opens_at' => 'required|date', 'closes_at' => 'required|date|after:opens_at|after:now', 'duration_minutes' => 'required|integer|min:1|max:240'])->validate();

            $data['opens_at'] = Carbon::parse($data['opens_at'])->utc()->format('Y-m-d H:i:s');
            $data['closes_at'] = Carbon::parse($data['closes_at'])->utc()->format('Y-m-d H:i:s');

            return DB::table('quizzes')->insertGetId($data + ['course_id' => $course->id, 'questions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @param array<string, mixed> $input */
    public function author(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            $this->lock();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('manage', Course::findOrFail($quiz->course_id));
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'action' => 'required|in:question,remove,publish'])->validate();
            abort_if($quiz->status !== 'draft' || (int) $data['version'] !== $quiz->version, 409, 'This quiz is published or has changed. Reload to review it.');
            $questions = json_decode($quiz->questions, true);
            $update = [];
            if ($data['action'] === 'question') {
                abort_if(count($questions) >= 50, 422, 'A quiz supports up to 50 questions.');
                $question = Validator::make($input, ['prompt' => 'required|string|max:5000', 'options' => 'required|array|list|size:4', 'options.*' => 'required|string|max:1000|distinct', 'correct' => 'required|integer|between:0,3', 'points' => 'required|integer|between:1,100'])->validate();
                $questions[] = $question;
            } elseif ($data['action'] === 'remove') {
                $remove = Validator::make($input, ['index' => 'required|integer|min:0'])->validate();
                abort_unless(isset($questions[$remove['index']]), 422, 'Question does not exist.');
                array_splice($questions, $remove['index'], 1);
            } else {
                abort_if(! $questions || ! now()->lt(Carbon::parse($quiz->closes_at)), 422, 'Add questions and publish before the closing time.');
                $update = ['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()];
            }
            DB::table('quizzes')->where('id', $id)->update($update + ['questions' => json_encode($questions), 'version' => $quiz->version + 1, 'updated_at' => now()]);
        });
    }

    public function start(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->lock();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('participate', Course::findOrFail($quiz->course_id));
            abort_unless($quiz->status === 'published', 404);
            if (DB::table('quiz_attempts')->where('quiz_id', $id)->where('user_id', $actor->id)->exists()) {
                return;
            }
            abort_unless(now()->gte(Carbon::parse($quiz->opens_at)) && now()->lt(Carbon::parse($quiz->closes_at)), 422, 'The quiz is outside its availability window.');
            DB::table('quiz_attempts')->insert(['quiz_id' => $id, 'user_id' => $actor->id, 'started_at' => now(), 'deadline_at' => now()->addMinutes($quiz->duration_minutes)->min(Carbon::parse($quiz->closes_at)), 'answers' => '[]']);
        });
    }

    /** @param array<string, mixed> $input */
    public function answer(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            $this->lock();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('participate', Course::findOrFail($quiz->course_id));
            abort_unless($quiz->status === 'published', 404);
            $attempt = DB::table('quiz_attempts')->where('quiz_id', $id)->where('user_id', $actor->id)->first();
            abort_unless($attempt, 422, 'Start the quiz first.');
            if ($attempt->submitted_at) {
                return;
            }
            $questions = json_decode($quiz->questions, true);
            $expired = now()->gte(Carbon::parse($attempt->deadline_at));
            $answers = json_decode($attempt->answers, true);
            $finish = $expired;
            if (! $expired) {
                $rules = ['version' => 'required|integer|min:0', 'action' => 'required|in:save,submit', 'answers' => 'sometimes|array:'.implode(',', array_keys($questions))];
                foreach ($questions as $index => $question) {
                    $rules["answers.$index"] = 'nullable|integer|between:0,3';
                }
                $data = Validator::make($input, $rules)->validate();
                abort_if((int) $data['version'] !== $attempt->version, 409, 'Answers were saved in another tab. Reload before continuing.');
                $answers = [];
                foreach ($questions as $index => $question) {
                    $answers[$index] = isset($data['answers'][$index]) ? (int) $data['answers'][$index] : null;
                }
                $finish = $data['action'] === 'submit';
            }
            $score = null;
            if ($finish) {
                $score = $this->score($questions, $answers);
            }
            DB::table('quiz_attempts')->where('id', $attempt->id)->update(['answers' => json_encode($answers), 'version' => $attempt->version + 1, 'score' => $score, 'submitted_at' => $finish ? now() : null]);
        });
    }

    public function finalizeExpired(): int
    {
        $ids = DB::table('quiz_attempts')->whereNull('submitted_at')->where('deadline_at', '<=', now())->orderBy('id')->limit(500)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $this->lock();
                $attempt = DB::table('quiz_attempts')->where('id', $id)->lockForUpdate()->first();
                if (! $attempt || $attempt->submitted_at || now()->lt(Carbon::parse($attempt->deadline_at))) {
                    return 0;
                }
                $quiz = $this->find($attempt->quiz_id);
                $score = $this->score(json_decode($quiz->questions, true), json_decode($attempt->answers, true));
                DB::table('quiz_attempts')->where('id', $id)->update(['score' => $score, 'submitted_at' => now(), 'version' => $attempt->version + 1]);

                return 1;
            });
        }

        return $count;
    }

    /**
     * @param  array<int, array{correct: int|string, points: int|string}>  $questions
     * @param  array<int, int|null>  $answers
     */
    private function score(array $questions, array $answers): int
    {
        $score = 0;
        foreach ($questions as $index => $question) {
            if (isset($answers[$index]) && (int) $answers[$index] === (int) $question['correct']) {
                $score += $question['points'];
            }
        }

        return $score;
    }

    private function lock(): void
    {
        DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
    }

    private function find(int $id): stdClass
    {
        $quiz = DB::table('quizzes')->where('id', $id)->lockForUpdate()->first();
        abort_unless($quiz, 404);

        return $quiz;
    }
}
