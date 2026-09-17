<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyNote extends Model
{
    protected $fillable = ['model_name', 'ai_configuration_version', 'edited_at', 'user_id', 'course_id', 'lesson_id', 'material_id', 'source_title', 'title', 'request_key', 'status', 'provider', 'content', 'error', 'generated_at'];

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
}
