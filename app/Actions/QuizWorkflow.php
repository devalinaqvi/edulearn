<?php

namespace App\Actions;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\User;
use App\Services\DisplayTime;
use App\Services\QuizQuestionProvider;
use App\Services\SourceText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

class QuizWorkflow
{
    /** @param array<string, mixed> $input */
    public function create(User $actor, Course $course, array $input): int
    {
        return DB::transaction(function () use ($actor, $course, $input) {
            WriteLock::acquire();
            Gate::forUser($actor->fresh())->authorize('manage', $course->fresh());
            // Staff type the window in the display timezone; convert before the relative rules run.
            $input = DisplayTime::normalize($input, 'opens_at', 'closes_at');
            $data = Validator::make($input, ['title' => 'required|string|max:160', 'instructions' => 'required|string|max:10000', 'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date', 'duration_minutes' => 'required|integer|min:1|max:240'])->validate();
            $data['opens_at'] = $data['opens_at'] ?? null;
            $data['closes_at'] = $data['closes_at'] ?? null;
            $this->assertWindow($data['opens_at'], $data['closes_at']);

            return DB::table('quizzes')->insertGetId($data + ['course_id' => $course->id, 'questions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * Edit a quiz's presentation and availability, before or after publication.
     *
     * Questions are deliberately not reachable from here. Publishing a quiz freezes its question
     * set so that an attempt can never be scored against a paper different from the one it was
     * sat under; that rule lives in author() and is unchanged. Everything that does not restate
     * a sat paper stays editable:
     *
     *  - title and instructions throughout;
     *  - the availability window and attempt duration, which govern future attempts only.
     *
     * Attempts already started keep the deadline_at recorded when they began, so moving the
     * window never shortens or extends time a learner was already promised.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateDetails(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            WriteLock::acquire();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('manage', Course::findOrFail($quiz->course_id));

            $input = DisplayTime::normalize($input, 'opens_at', 'closes_at');
            $data = Validator::make($input, [
                'version' => 'required|integer|min:0',
                'title' => 'required|string|max:160',
                'instructions' => 'required|string|max:10000',
                'opens_at' => 'nullable|date',
                'closes_at' => 'nullable|date',
                'duration_minutes' => 'required|integer|min:1|max:240',
            ])->validate();

            abort_if((int) $data['version'] !== $quiz->version, 409, 'This quiz changed. Reload before saving.');
            $this->assertWindow($data['opens_at'] ?? null, $data['closes_at'] ?? null, $quiz->closes_at !== null);

            DB::table('quizzes')->where('id', $id)->update([
                'title' => $data['title'],
                'instructions' => $data['instructions'],
                'opens_at' => $data['opens_at'] ?? null,
                'closes_at' => $data['closes_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'],
                'version' => $quiz->version + 1,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Append AI-written questions to a DRAFT quiz, for a human to review.
     *
     * Generated questions are ordinary draft questions: they enter the same authoring path, can
     * be removed individually, and reach learners only when a person publishes the quiz. Nothing
     * here publishes, and a published quiz is refused outright — its question set is frozen.
     *
     * @param  array<string, mixed>  $input
     * @return array{added: int, requested: int}
     */
    public function generateQuestions(User $actor, int $id, array $input, QuizQuestionProvider $provider, SourceText $extractor): array
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'source_type' => 'required|in:lesson,material',
            'source_id' => 'required|integer',
            'count' => 'required|integer|min:1|max:'.QuizQuestionProvider::MAX_QUESTIONS,
            'difficulty' => 'required|in:foundational,intermediate,challenging',
        ])->validate();

        // Read the source and call the provider outside the transaction: a slow external request
        // must not hold the shared write lock, which every other mutation queues behind.
        $quiz = $this->find($id);
        $course = Course::findOrFail($quiz->course_id);
        Gate::forUser($actor->fresh())->authorize('manage', $course);
        abort_if($quiz->status !== 'draft', 409, 'A published quiz cannot take new questions. Its question set is frozen so that every attempt is scored against the paper it was sat under.');

        $source = $data['source_type'] === 'lesson'
            ? Lesson::whereKey($data['source_id'])->where('course_id', $course->id)->firstOrFail()
            : Material::whereKey($data['source_id'])->where('course_id', $course->id)->firstOrFail();

        $generated = $provider->generate($extractor->extract($source), (int) $data['count'], $data['difficulty']);

        return DB::transaction(function () use ($id, $data, $generated) {
            WriteLock::acquire();
            $current = $this->find($id);
            abort_if($current->status !== 'draft' || (int) $data['version'] !== $current->version, 409, 'This quiz changed while the questions were being written. Reload and try again.');

            $questions = json_decode($current->questions, true);
            $existing = array_map(fn ($question) => mb_strtolower(trim($question['prompt'] ?? '')), $questions);

            $added = 0;
            foreach ($generated as $question) {
                if (count($questions) >= 50 || in_array(mb_strtolower($question['prompt']), $existing, true)) {
                    continue;
                }
                $questions[] = $question;
                $existing[] = mb_strtolower($question['prompt']);
                $added++;
            }

            DB::table('quizzes')->where('id', $id)->update([
                'questions' => json_encode($questions),
                'version' => $current->version + 1,
                'updated_at' => now(),
            ]);

            return ['added' => $added, 'requested' => (int) $data['count']];
        });
    }

    /** @param array<string, mixed> $input */
    public function author(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            WriteLock::acquire();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('manage', Course::findOrFail($quiz->course_id));
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'action' => 'required|in:question,remove,publish'])->validate();
            abort_if($quiz->status !== 'draft' || (int) $data['version'] !== $quiz->version, 409, 'This quiz is published or has changed. Reload to review it.');
            $questions = json_decode($quiz->questions, true);
            $update = [];
            if ($data['action'] === 'question') {
                abort_if(count($questions) >= 50, 422, 'A quiz supports up to 50 questions.');
                $type = Validator::make($input, ['type' => 'sometimes|required|in:mcq,short'])->validate()['type'] ?? 'mcq';
                $rules = ['prompt' => 'required|string|max:5000', 'points' => 'required|integer|between:1,100'];
                if ($type === 'mcq') {
                    $rules += ['options' => 'required|array|list|size:4', 'options.*' => 'required|string|max:1000|distinct', 'correct' => 'required|integer|between:0,3'];
                }
                $question = Validator::make($input, $rules)->validate() + ['type' => $type];
                $questions[] = $question;
            } elseif ($data['action'] === 'remove') {
                $remove = Validator::make($input, ['index' => 'required|integer|min:0'])->validate();
                abort_unless(isset($questions[$remove['index']]), 422, 'Question does not exist.');
                array_splice($questions, $remove['index'], 1);
            } else {
                abort_if(! $questions, 422, 'Add at least one question before publishing.');
                abort_if($quiz->closes_at !== null && ! now()->lt(Carbon::parse($quiz->closes_at)), 422, 'This quiz closed before it was published. Extend its closing time first.');
                $update = ['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()];
            }
            DB::table('quizzes')->where('id', $id)->update($update + ['questions' => json_encode($questions), 'version' => $quiz->version + 1, 'updated_at' => now()]);
        });
    }

    public function start(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            WriteLock::acquire();
            $quiz = $this->find($id);
            Gate::forUser($actor->fresh())->authorize('participate', Course::findOrFail($quiz->course_id));
            abort_unless($quiz->status === 'published', 404);
            if (DB::table('quiz_attempts')->where('quiz_id', $id)->where('user_id', $actor->id)->exists()) {
                return;
            }
            abort_unless($this->isOpen($quiz), 422, 'The quiz is outside its availability window.');
            // An attempt is always time-limited by its duration; a closing time can only shorten it.
            $deadline = now()->addMinutes($quiz->duration_minutes);
            if ($quiz->closes_at !== null) {
                $deadline = $deadline->min(Carbon::parse($quiz->closes_at));
            }
            DB::table('quiz_attempts')->insert(['quiz_id' => $id, 'user_id' => $actor->id, 'started_at' => now(), 'deadline_at' => $deadline, 'answers' => '[]']);
        });
    }

    /** @param array<string, mixed> $input */
    public function answer(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            WriteLock::acquire();
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
                    $rules["answers.$index"] = ($question['type'] ?? 'mcq') === 'short' ? 'nullable|string|max:5000' : 'nullable|integer|between:0,3';
                }
                $data = Validator::make($input, $rules)->validate();
                abort_if((int) $data['version'] !== $attempt->version, 409, 'Answers were saved in another tab. Reload before continuing.');
                $answers = [];
                foreach ($questions as $index => $question) {
                    $answers[$index] = isset($data['answers'][$index]) ? (($question['type'] ?? 'mcq') === 'short' ? $data['answers'][$index] : (int) $data['answers'][$index]) : null;
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
                WriteLock::acquire();
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
            if (($question['type'] ?? 'mcq') !== 'short' && isset($answers[$index]) && (int) $answers[$index] === (int) $question['correct']) {
                $score += $question['points'];
            }
        }

        return $score;
    }

    /** @param array<string, mixed> $input */
    public function review(User $actor, int $attemptId, array $input, bool $publish = false): void
    {
        DB::transaction(function () use ($actor, $attemptId, $input, $publish) {
            WriteLock::acquire();
            $attempt = DB::table('quiz_attempts')->find($attemptId);
            abort_unless($attempt, 404);
            $quiz = $this->find($attempt->quiz_id);
            Gate::forUser($actor->fresh())->authorize('manage', Course::findOrFail($quiz->course_id));
            abort_unless($attempt->submitted_at, 409, 'The attempt must be finalized before review.');
            $questions = json_decode($quiz->questions, true);
            $short = array_filter($questions, fn ($question) => ($question['type'] ?? 'mcq') === 'short');
            $rules = ['version' => 'required|integer|min:0', 'reason' => 'required|string|max:1000'];
            if ($publish) {
                $rules['confirm'] = 'accepted';
            } else {
                $rules['feedback'] = 'nullable|string|max:10000';
                $rules['scores'] = $short ? 'required|array:'.implode(',', array_keys($short)) : 'nullable|array';
                foreach ($short as $index => $question) {
                    $rules["scores.$index"] = 'required|numeric|decimal:0,2|min:0|max:'.$question['points'];
                }
            }
            $data = Validator::make($input, $rules)->validate();
            abort_unless((int) $data['version'] === $attempt->version, 409, 'This review changed. Reload before saving.');
            if ($publish) {
                // With a closing time, nobody may see a result while others can still sit the quiz.
                // Without one there is no such moment, so the learner's own finalized attempt is
                // the gate; that it is finalized was already asserted above.
                abort_if($quiz->closes_at !== null && now()->lt(Carbon::parse($quiz->closes_at)), 422, 'Results may be released only after the quiz closes.');
                abort_if($short && ! $attempt->reviewed_at, 422, 'Grade every short answer before publishing.');
                if ($attempt->published_version === $attempt->version) {
                    return;
                }
                $result = ['score' => $attempt->score, 'feedback' => $attempt->review_feedback];
                $update = ['published_result' => json_encode($result), 'published_version' => $attempt->version, 'result_published_at' => now()];
                $version = $attempt->version;
            } else {
                $manual = $short ? array_intersect_key($data['scores'], $short) : [];
                $cents = $this->score($questions, json_decode($attempt->answers, true)) * 100;
                $cents += array_sum(array_map(fn ($score) => (int) round($score * 100), $manual));
                $result = ['score' => number_format($cents / 100, 2, '.', ''), 'feedback' => $data['feedback'] ?? null, 'manual_scores' => $manual];
                $version = $attempt->version + 1;
                $update = ['score' => $result['score'], 'review_feedback' => $result['feedback'], 'manual_scores' => json_encode($manual), 'reviewed_at' => now(), 'version' => $version];
            }
            DB::table('quiz_attempts')->where('id', $attemptId)->update($update);
            DB::table('quiz_assessment_changes')->insert(['quiz_attempt_id' => $attemptId, 'actor_id' => $actor->id, 'event' => $publish ? 'published' : 'reviewed', 'version' => $version, 'result' => json_encode($result), 'reason' => $data['reason'], 'created_at' => now()]);
        });
    }

    /** Whether the quiz is currently sittable. A missing bound is simply not a bound. */
    private function isOpen(stdClass $quiz): bool
    {
        $opened = $quiz->opens_at === null || now()->gte(Carbon::parse($quiz->opens_at));
        $stillOpen = $quiz->closes_at === null || now()->lt(Carbon::parse($quiz->closes_at));

        return $opened && $stillOpen;
    }

    /**
     * A window is optional, but an incoherent one is not allowed: it may not close before it
     * opens, and a newly set closing time may not already be in the past.
     */
    private function assertWindow(?string $opensAt, ?string $closesAt, bool $allowPastClose = false): void
    {
        if ($opensAt !== null && $closesAt !== null && ! Carbon::parse($closesAt)->gt(Carbon::parse($opensAt))) {
            throw ValidationException::withMessages(['closes_at' => 'The closing time must be later than the opening time.']);
        }
        if ($closesAt !== null && ! $allowPastClose && ! Carbon::parse($closesAt)->isFuture()) {
            throw ValidationException::withMessages(['closes_at' => 'The closing time must be in the future. Leave it empty for a quiz with no deadline.']);
        }
    }

    private function find(int $id): stdClass
    {
        $quiz = DB::table('quizzes')->where('id', $id)->lockForUpdate()->first();
        abort_unless($quiz, 404);

        return $quiz;
    }
}
