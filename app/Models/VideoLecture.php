<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoLecture extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'version' => 'integer',
            'position' => 'integer',
        ];
    }

    /** A lecture is watchable only when it is published and its media finished processing. */
    public function isPlayable(): bool
    {
        return $this->status === 'published' && $this->processing_status === 'ready';
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(VideoLectureTrack::class);
    }

    public function captions(): HasMany
    {
        return $this->tracks()->where('kind', 'captions');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(VideoLectureProgress::class);
    }
}
