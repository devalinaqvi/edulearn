<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    protected $fillable = ['uploader_id', 'size_bytes', 'uploaded_at', 'course_id', 'lesson_id', 'title', 'path', 'original_name', 'format', 'version', 'status', 'archived_at', 'archived_by'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'archived_at' => 'datetime', 'size_bytes' => 'integer', 'version' => 'integer'];
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
