<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = ['uploader_id', 'size_bytes', 'uploaded_at', 'course_id', 'lesson_id', 'title', 'path', 'original_name', 'format'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
