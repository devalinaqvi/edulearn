<?php

namespace App\Jobs;

use App\Models\StudyNote;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\NotesProvider;
use App\Services\SourceText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Gate;

class GenerateStudyNotes implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public int $noteId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('note:'.$this->noteId))->releaseAfter(10)->expireAfter(75)];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(SourceText $extractor, NotesProvider $provider): void
    {
        $note = StudyNote::find($this->noteId);
        if (! $note || in_array($note->status, ['completed', 'failed'])) {
            return;
        }
        $configuration = app(AiSettings::class)->current();
        if (! $configuration->enabled || $configuration->version !== $note->ai_configuration_version) {
            $note->update(['status' => 'failed', 'error' => 'AI configuration changed or was disabled. Request new notes.']);

            return;
        }
        $user = User::find($note->user_id);
        if (! $user || ! Gate::forUser($user->fresh())->allows('studySource', $note->course)) {
            $note->update(['status' => 'failed', 'error' => 'You no longer have access to this course source.']);

            return;
        }
        $note->update(['status' => 'processing', 'error' => null]);
        try {
            $source = $note->lesson ?? $note->material;
            if (! $source) {
                throw new \RuntimeException('Source unavailable.');
            }
            $text = $extractor->extract($source);
            // Refuse a changed source rather than silently generating from a different version.
            $key = hash('sha256', ($note->lesson_id ? 'lesson:' : 'material:').$source->id.':'.$text);
            if (! hash_equals($note->request_key, $key)) {
                $note->update(['status' => 'failed', 'error' => 'The source changed. Request new notes from the course.']);

                return;
            }
            $content = $provider->generate($text, $note->provider, $note->model_name);
            $currentConfiguration = app(AiSettings::class)->current();
            if (! $currentConfiguration->enabled || $currentConfiguration->version !== $note->ai_configuration_version || ! Gate::forUser($user->fresh())->allows('studySource', $note->course->fresh())) {
                $note->update(['status' => 'failed', 'content' => null, 'error' => 'Your course access changed before generation finished.']);

                return;
            }
            $note->update(['status' => 'completed', 'content' => $content, 'generated_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            if ($this->attempts() >= 3) {
                $this->failed($e);

                return;
            }
            $note->update(['status' => 'pending', 'error' => 'Generation was interrupted. An automatic retry is queued.']);
            // Sanitized exception only: original provider/transport details are discarded.
            throw new \RuntimeException('Study notes generation temporarily failed.');
        }
    }

    public function failed(?\Throwable $e): void
    {
        StudyNote::whereKey($this->noteId)->where('status', '!=', 'completed')->update(['status' => 'failed', 'error' => 'Generation could not finish after bounded retries. Check the source or ask an administrator to check the provider and worker.']);
    }
}
