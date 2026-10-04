<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Course extends Model
{
    protected $fillable = ['code', 'instructor_id', 'title', 'description', 'status'];

    protected static function booted(): void
    {
        static::creating(function (Course $course) {
            $course->code = strtoupper(trim($course->code ?: 'EL-'.Str::ulid()));
        });
    }

    public function coInstructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_instructors');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position')->orderBy('id');
    }

    /** Only the lessons that currently form the course, for learner-facing views and progress. */
    public function activeLessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->where('status', 'active')->orderBy('position')->orderBy('id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class)->orderBy('due_at');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class)->latest();
    }

    public function progressFor(User $user): int
    {
        // An archived lesson is no longer part of the course, so it neither adds to the total
        // nor counts a completion a learner earned before it was withdrawn.
        $total = $this->activeLessons()->count();
        $done = LessonCompletion::where('user_id', $user->id)->whereIn('lesson_id', $this->activeLessons()->select('id'))->count();

        return $total ? (int) round(100 * $done / $total) : 0;
    }
}
