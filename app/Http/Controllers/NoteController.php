<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateStudyNotes;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\User;
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
        $data = $r->validate(['source_type' => ['required', Rule::in(['lesson', 'material'])], 'source_id' => 'required|integer']);
        $source = ($data['source_type'] === 'lesson' ? Lesson::class : Material::class)::findOrFail($data['source_id']);
        Gate::authorize('participate', $source->course);
        $text = $extractor->extract($source);
        $key = hash('sha256', $data['source_type'].':'.$source->id.':'.$text);
        $note = DB::transaction(function () use ($r, $data, $source, $key) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $existing = StudyNote::where('user_id', $user->id)->where('request_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $count = $user->ai_usage_date === today()->toDateString() ? $user->ai_usage_count : 0;
            abort_if($count >= config('study.daily_limit'), 429, 'Your daily study-note limit has been reached. Try again tomorrow (UTC).');
            $user->forceFill(['ai_usage_date' => today()->toDateString(), 'ai_usage_count' => $count + 1])->save();
            $note = StudyNote::create(['user_id' => $user->id, 'course_id' => $source->course_id, $data['source_type'].'_id' => $source->id, 'source_title' => $source->title, 'title' => $source->title.' — study notes', 'request_key' => $key, 'provider' => config('study.provider')]);
            GenerateStudyNotes::dispatch($note->id); // Database queue insertion shares this transaction.

            return $note;
        });

        return redirect()->route('notes.show', $note)->with('status', 'Your note request is saved. Duplicate requests open the existing note.');
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
        $note->update($r->validate(['title' => 'required|string|max:200']));

        return back()->with('status', 'Note renamed.');
    }

    public function destroy(Request $r, StudyNote $note)
    {
        $this->own($r, $note);
        abort_if(in_array($note->status, ['pending', 'processing']), 409, 'Wait for generation to finish before deleting this request.');
        $note->delete();

        return redirect()->route('notes.index')->with('status', 'Note deleted.');
    }
}
