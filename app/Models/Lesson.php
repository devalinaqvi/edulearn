<?php

namespace App\Models;

use App\Services\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $fillable = ['course_id', 'title', 'body', 'body_format', 'position', 'status', 'archived_at', 'archived_by', 'version'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'version' => 'integer'];
    }

    public function isRichText(): bool
    {
        return $this->body_format === 'html';
    }

    /** The body as plain text, for AI sources and anywhere markup would be noise. */
    public function plainBody(): string
    {
        return $this->isRichText() ? RichText::toPlainText($this->body) : (string) $this->body;
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** Lessons a learner can see. Archived lessons stay on record but leave the course. */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
