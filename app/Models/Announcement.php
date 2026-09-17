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
        static::creating(fn (Announcement $announcement) => $announcement->published_at ??= now());
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereNotNull('published_at')->where(function ($audience) use ($user) {
            $audience->whereNull('course_id')->orWhereHas('course', function ($course) use ($user) {
                if ($user->role === 'student') {
                    $course->where('status', 'published')->whereHas('enrollments', fn ($enrollment) => $enrollment->where('user_id', $user->id));
                } elseif ($user->role === 'instructor') {
                    $course->where(fn ($assigned) => $assigned->where('instructor_id', $user->id)->orWhereHas('coInstructors', fn ($teacher) => $teacher->where('users.id', $user->id)));
                }
            });
        });
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
