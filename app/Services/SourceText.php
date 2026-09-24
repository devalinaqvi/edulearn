<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Material;
use App\Models\VideoLecture;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SourceText
{
    public function __construct(private DocumentText $documents) {}

    public function extract(Lesson|Material|VideoLecture $source): string
    {
        if ($source instanceof Lesson) {
            $text = $source->body;
        } elseif ($source instanceof VideoLecture) {
            $text = $this->fromLecture($source);
        } else {
            $text = $this->fromMaterial($source);
        }

        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw ValidationException::withMessages(['source' => 'The source must contain readable UTF-8 text.']);
        }
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages(['source' => 'This source contains no readable text.']);
        }
        if (mb_strlen($text) > app(AiSettings::class)->current()->max_input_chars) {
            throw ValidationException::withMessages(['source' => 'This source exceeds the configured input limit. Split it into shorter lessons or materials.']);
        }

        return $text;
    }

    private function fromMaterial(Material $material): string
    {
        if ($material->isArchived()) {
            throw ValidationException::withMessages(['source' => 'This material has been archived. Study notes already generated from it remain in your library.']);
        }
        if (! Storage::disk('local')->exists($material->path)) {
            throw ValidationException::withMessages(['source' => 'The source file is no longer available.']);
        }

        return $this->documents->extract(Storage::disk('local')->path($material->path), $material->format);
    }

    /**
     * Only a stored transcript is a valid lecture source. Speech-to-text is not implemented,
     * so a lecture without a transcript cannot produce notes.
     */
    private function fromLecture(VideoLecture $lecture): string
    {
        if (! $lecture->isPlayable()) {
            throw ValidationException::withMessages(['source' => 'This lecture is not currently published.']);
        }
        $transcript = $lecture->tracks()->where('kind', 'transcript')->whereNotNull('transcript_text')->orderByDesc('version')->first();
        if (! $transcript) {
            throw ValidationException::withMessages(['source' => 'This lecture has no transcript yet. Automatic speech-to-text is not available; ask your instructor to add a transcript.']);
        }

        return 'Video lecture transcript: '.$lecture->title."\n\n".$transcript->transcript_text;
    }
}
