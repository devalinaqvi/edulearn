<?php

namespace App\Models;

use App\Services\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = [
        'course_id', 'title', 'instructions', 'instructions_format', 'due_at', 'max_marks',
        'rubric', 'rubric_version', 'version', 'status', 'published_at', 'published_by',
        'archived_at', 'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'rubric' => 'array',
            'rubric_version' => 'integer',
            'version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Recorded grades are stored against the maximum that was in force when they were awarded,
     * and rubric criteria must total it, so the maximum is frozen once any work is submitted.
     */
    public function hasRecordedWork(): bool
    {
        return $this->submissions()->exists();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isRichText(): bool
    {
        return $this->instructions_format === 'html';
    }

    /** The brief as prose, for AI sources and anywhere markup would be noise. */
    public function plainInstructions(): string
    {
        return $this->isRichText() ? RichText::toPlainText($this->instructions) : (string) $this->instructions;
    }

    /**
     * Assignments a learner may see and work on.
     *
     * A draft has never been issued and an archived assignment has been withdrawn; neither is
     * coursework, so neither appears. Work already submitted against an archived assignment is
     * untouched and still reachable by staff.
     */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', 'published');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(AssignmentMedium::class);
    }
}
