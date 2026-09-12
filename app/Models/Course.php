<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('position')->orderBy('id');
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class)->orderBy('due_at');
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class)->latest();
    }

    public function progressFor(User $user): int
    {
        $total = $this->lessons()->count();
        $done = LessonCompletion::where('user_id', $user->id)->whereIn('lesson_id', $this->lessons()->select('id'))->count();

        return $total ? (int) round(100 * $done / $total) : 0;
    }
}
