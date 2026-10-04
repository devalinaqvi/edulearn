<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    protected $fillable = ['author_id', 'published_at', 'course_id', 'title', 'body'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement) {
            // Distinguish "no publication time was supplied", which still means publish now, from
            // an explicit null, which is a draft. A ??= cannot tell the two apart.
            if (! array_key_exists('published_at', $announcement->getAttributes())) {
                $announcement->published_at = now();
            }
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        // A future published_at is a scheduled announcement, not a live one: compare against the
        // clock rather than only testing for presence, or scheduling would publish immediately.
        $query->whereNotNull('published_at')->where('published_at', '<=', now())->where(function ($audience) use ($user) {
            $audience->whereNull('course_id')->orWhereHas('course', function ($course) use ($user) {
                if ($user->role === 'student') {
                    $course->where('status', 'published')->whereHas('enrollments', fn ($enrollment) => $enrollment->where('user_id', $user->id));
                } elseif ($user->role === 'instructor') {
                    $course->where(fn ($assigned) => $assigned->where('instructor_id', $user->id)->orWhereHas('coInstructors', fn ($teacher) => $teacher->where('users.id', $user->id)));
                }
            });
        });
    }

    /** draft (never released), scheduled (released later), or published (visible now). */
    public function state(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }

        return $this->published_at->isFuture() ? 'scheduled' : 'published';
    }

    public function isPending(): bool
    {
        return $this->state() !== 'published';
    }

    /** Announcements an author may still edit, because nobody has seen them yet. */
    public function scopePending(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '>', now()));
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')->withPivot('read_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
