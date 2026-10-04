<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = ['course_id', 'title', 'instructions', 'due_at', 'max_marks', 'rubric', 'rubric_version', 'version'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'rubric' => 'array', 'rubric_version' => 'integer', 'version' => 'integer'];
    }

    /**
     * Recorded grades are stored against the maximum that was in force when they were awarded,
     * and rubric criteria must total it, so the maximum is frozen once any work is submitted.
     */
    public function hasRecordedWork(): bool
    {
        return $this->submissions()->exists();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
