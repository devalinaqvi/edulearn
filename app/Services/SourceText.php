<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Material;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SourceText
{
    public function extract(Lesson|Material $source): string
    {
        if ($source instanceof Lesson) {
            $text = $source->body;
        } else {
            if (! in_array($source->format, ['txt', 'md'])) {
                throw ValidationException::withMessages(['source' => 'AI notes support UTF-8 TXT, Markdown, and lesson text only.']);
            }
            if (! Storage::disk('local')->exists($source->path)) {
                throw ValidationException::withMessages(['source' => 'The source file is no longer available.']);
            }
            $text = Storage::disk('local')->get($source->path);
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
}
