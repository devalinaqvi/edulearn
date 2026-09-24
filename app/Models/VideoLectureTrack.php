<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoLectureTrack extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(VideoLecture::class, 'video_lecture_id');
    }
}
