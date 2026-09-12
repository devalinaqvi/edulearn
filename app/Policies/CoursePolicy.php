<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CoursePolicy
{
    public function manage(User $user, Course $course): bool
    {
        return $user->is_active && ($user->role === 'admin' || ($user->role === 'instructor' && ($course->instructor_id === $user->id || $course->coInstructors()->where('users.id', $user->id)->exists())));
    }

    public function view(User $user, Course $course): bool
    {
        return $this->manage($user, $course) || $this->participate($user, $course);
    }

    public function participate(User $user, Course $course): bool
    {
        return $user->is_active && $user->role === 'student' && $course->status === 'published' && $course->enrollments()->where('user_id', $user->id)->exists();
    }

    public function enroll(User $user, Course $course): bool
    {
        return $user->is_active && $user->role === 'student' && $course->status === 'published'
            && DB::table('course_access_changes')->where('course_id', $course->id)->where('user_id', $user->id)->whereIn('action', ['enroll', 'remove'])->orderByDesc('id')->value('action') !== 'remove';
    }

    public function studySource(User $user, Course $course): bool
    {
        return $this->participate($user, $course);
    }
}
