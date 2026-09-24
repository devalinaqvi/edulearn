<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyNote extends Model
{
    protected $fillable = ['model_name', 'ai_configuration_version', 'edited_at', 'user_id', 'course_id', 'lesson_id', 'material_id', 'video_lecture_id', 'source_title', 'title', 'request_key', 'status', 'provider', 'content', 'error', 'generated_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'ai_configuration_version' => 'integer', 'generated_at' => 'datetime'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function videoLecture()
    {
        return $this->belongsTo(VideoLecture::class);
    }

    /** The note's source record, whichever of the three source kinds it was requested from. */
    public function source(): Lesson|Material|VideoLecture|null
    {
        return $this->lesson ?? $this->material ?? $this->videoLecture;
    }

    /** Prefix used when hashing source content, so a key can never collide across source kinds. */
    public function sourceType(): string
    {
        return $this->lesson_id ? 'lesson' : ($this->video_lecture_id ? 'lecture' : 'material');
    }
}
