<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lms_write_locks')) {
            Schema::create('lms_write_locks', fn (Blueprint $table) => $table->unsignedTinyInteger('id')->primary());
        }
        DB::table('lms_write_locks')->updateOrInsert(['id' => 1]);
        if (! Schema::hasTable('course_instructors')) {
            Schema::create('course_instructors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->unique(['course_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('course_access_changes')) {
            Schema::create('course_access_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('action');
                $table->text('reason');
                $table->timestamp('created_at');
            });
        }

        if (Schema::hasTable('sections') && Schema::hasColumn('sections', 'learning_course_id')) {
            DB::transaction(function () {
                $linkedCourses = DB::table('sections')->join('course_offerings', 'course_offerings.id', '=', 'sections.course_offering_id')->join('catalog_courses', 'catalog_courses.id', '=', 'course_offerings.catalog_course_id')->whereNotNull('sections.learning_course_id')->select('sections.learning_course_id', 'sections.name', 'catalog_courses.code', 'catalog_courses.title')->get();
                foreach ($linkedCourses as $linked) {
                    DB::table('courses')->where('id', $linked->learning_course_id)->where('title', $linked->code.' · '.$linked->title.' · Section '.$linked->name)->update(['title' => $linked->title]);
                    DB::table('courses')->where('id', $linked->learning_course_id)->where('description', 'University demonstration learning space for an approved section registration. Content is synthetic.')->update(['description' => 'Online learning course with synthetic practice content. Learn at your own pace.']);
                    DB::table('assignments')->where('course_id', $linked->learning_course_id)->where('instructions', 'Explain one idea from the lesson and ask one question for your instructor. This is synthetic practice work, not an official university result.')->update(['instructions' => 'Explain one idea from the lesson and ask one question for your instructor. This is synthetic online practice work.']);
                }
                $registrations = DB::table('registrations')->join('program_enrollments', 'program_enrollments.id', '=', 'registrations.program_enrollment_id')->join('sections', 'sections.id', '=', 'registrations.section_id')->where('registrations.status', 'registered')->where('program_enrollments.status', 'active')->whereNotNull('sections.learning_course_id')->select('program_enrollments.user_id', 'sections.learning_course_id')->distinct()->get();
                foreach ($registrations as $registration) {
                    if (! DB::table('enrollments')->where('user_id', $registration->user_id)->where('course_id', $registration->learning_course_id)->exists()) {
                        DB::table('enrollments')->insert(['user_id' => $registration->user_id, 'course_id' => $registration->learning_course_id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $instructors = Schema::hasTable('section_instructors') ? DB::table('section_instructors')->join('sections', 'sections.id', '=', 'section_instructors.section_id')->join('users', 'users.id', '=', 'section_instructors.user_id')->where('section_instructors.active', true)->whereIn('users.role', ['instructor', 'admin'])->whereNotNull('sections.learning_course_id')->select('section_instructors.user_id', 'sections.learning_course_id')->distinct()->get() : collect();
                foreach ($instructors as $instructor) {
                    DB::table('course_instructors')->updateOrInsert(['course_id' => $instructor->learning_course_id, 'user_id' => $instructor->user_id]);
                }
            });
        }

        foreach (['timetable_notifications', 'timetable_releases', 'attendance_corrections', 'attendance_changes', 'attendance_records', 'class_session_changes', 'class_sessions', 'rooms', 'academic_setup_changes', 'academic_audit_events', 'course_attempts', 'registrations', 'academic_holds', 'section_instructors', 'sections', 'course_offerings', 'course_prerequisites', 'curriculum_courses', 'program_enrollments', 'role_assignments', 'catalog_courses', 'academic_terms', 'curricula', 'programs', 'departments', 'faculties', 'campuses', 'universities', 'academic_write_locks'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('users')->whereNotIn('role', ['admin', 'instructor', 'student'])->update(['role' => 'student']);
    }

    public function down(): void
    {
        throw new RuntimeException('The online LMS conversion is forward-only. Restore the pre-migration database and matching source backup to recover removed records.');
    }
};
