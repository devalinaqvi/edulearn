<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateStudyNotes;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\User;
use App\Models\VideoLecture;
use App\Services\AiSettings;
use App\Services\SourceText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NoteController extends Controller
{
    public function index(Request $r)
    {
        return view('notes.index', ['notes' => StudyNote::where('user_id', $r->user()->id)->latest()->paginate(12)]);
    }

    public function store(Request $r, SourceText $extractor)
    {
        $configuration = app(AiSettings::class)->current();
        abort_unless($configuration->enabled, 503, 'AI features are currently disabled.');
        $data = $r->validate(['source_type' => ['required', Rule::in(['lesson', 'material', 'lecture'])], 'source_id' => 'required|integer']);
        $source = $this->sourceModel($data['source_type'])::findOrFail($data['source_id']);
        Gate::authorize('participate', $source->course);
        $text = $extractor->extract($source);
        $key = hash('sha256', $data['source_type'].':'.$source->id.':'.$text);
        $note = DB::transaction(function () use ($r, $data, $source, $key, $configuration) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $existing = StudyNote::where('user_id', $user->id)->where('request_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $count = $user->ai_usage_date === today()->toDateString() ? $user->ai_usage_count : 0;
            abort_if($count >= $configuration->daily_limit, 429, 'Your daily study-note limit has been reached. Try again tomorrow (UTC).');
            $user->forceFill(['ai_usage_date' => today()->toDateString(), 'ai_usage_count' => $count + 1])->save();
            $note = StudyNote::create(['user_id' => $user->id, 'course_id' => $source->course_id, $this->sourceColumn($data['source_type']) => $source->id, 'source_title' => $source->title, 'title' => $source->title.' — study notes', 'request_key' => $key, 'provider' => $configuration->provider, 'model_name' => $configuration->model, 'ai_configuration_version' => $configuration->version]);
            GenerateStudyNotes::dispatch($note->id); // Database queue insertion shares this transaction.

            return $note;
        });

        return redirect()->route('notes.show', $note)->with('status', 'Your note request is saved. Duplicate requests open the existing note.');
    }

    /** @return class-string<Lesson|Material|VideoLecture> */
    private function sourceModel(string $type): string
    {
        return match ($type) {
            'lesson' => Lesson::class,
            'lecture' => VideoLecture::class,
            default => Material::class,
        };
    }

    private function sourceColumn(string $type): string
    {
        return match ($type) {
            'lesson' => 'lesson_id',
            'lecture' => 'video_lecture_id',
            default => 'material_id',
        };
    }

    private function own(Request $r, StudyNote $note): void
    {
        abort_unless($note->user_id === $r->user()->id, 403);
    }

    public function show(Request $r, StudyNote $note)
    {
        $this->own($r, $note);
        Gate::authorize('participate', $note->course);

        return view('notes.show', compact('note'));
    }

    public function update(Request $r, StudyNote $note)
    {
        $this->own($r, $note);
        Gate::authorize('participate', $note->course);
        $data = $r->validate(['title' => 'required|string|max:200', 'content' => 'sometimes|required|string|max:20000']);
        abort_if(array_key_exists('content', $data) && $note->status !== 'completed', 409, 'Wait for completed notes before editing.');
        if (array_key_exists('content', $data)) {
            $data['edited_at'] = now();
        }
        $note->update($data);

        return back()->with('status', 'Note saved.');
    }

    public function regenerate(Request $r, StudyNote $note, SourceText $extractor)
    {
        $this->own($r, $note);
        $r->validate(['confirm' => 'accepted']);
        $result = DB::transaction(function () use ($r, $note, $extractor) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $note->refresh();
            Gate::forUser($user)->authorize('participate', $note->course->fresh());
            if (in_array($note->status, ['pending', 'processing'], true)) {
                return $note;
            }
            $configuration = app(AiSettings::class)->current();
            abort_unless($configuration->enabled, 503, 'AI features are currently disabled.');
            $source = $note->source();
            abort_unless($source, 404);
            $text = $extractor->extract($source);
            $key = hash('sha256', $note->sourceType().':'.$source->id.':'.$text);
            $existing = StudyNote::where('user_id', $user->id)->where('request_key', $key)->where('id', '!=', $note->id)->first();
            if ($existing) {
                return $existing;
            }
            $count = $user->ai_usage_date === today()->toDateString() ? $user->ai_usage_count : 0;
            abort_if($count >= $configuration->daily_limit, 429, 'Your daily study-note limit has been reached.');
            $user->forceFill(['ai_usage_date' => today()->toDateString(), 'ai_usage_count' => $count + 1])->save();
            $note->update(['request_key' => $key, 'status' => 'pending', 'content' => null, 'error' => null, 'edited_at' => null, 'generated_at' => null, 'provider' => $configuration->provider, 'model_name' => $configuration->model, 'ai_configuration_version' => $configuration->version]);
            GenerateStudyNotes::dispatch($note->id);

            return $note;
        });

        return redirect()->route('notes.show', $result)->with('status', 'Note generation requested.');
    }

    public function destroy(Request $r, StudyNote $note)
    {
        $this->own($r, $note);
        abort_if(in_array($note->status, ['pending', 'processing']), 409, 'Wait for generation to finish before deleting this request.');
        $note->delete();

        return redirect()->route('notes.index')->with('status', 'Note deleted.');
    }
}
