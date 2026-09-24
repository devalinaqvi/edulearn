<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoLectureProgress extends Model
{
    protected $table = 'video_lecture_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'watched_seconds' => 'integer',
            'furthest_seconds' => 'integer',
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'last_reported_at' => 'datetime',
        ];
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(VideoLecture::class, 'video_lecture_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
