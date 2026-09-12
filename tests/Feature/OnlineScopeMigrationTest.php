<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineScopeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite' && DB::connection()->getDatabaseName() !== 'acumen_university_test') {
            $this->markTestSkipped('Destructive upgrade fixture requires the isolated test database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_conversion_preserves_learning_and_transfers_only_eligible_access(): void
    {
        foreach (glob(base_path('tests/Fixtures/previous-schema/*.php')) as $path) {
            (require $path)->up();
        }
        $teacher = User::factory()->create(['role' => 'instructor']);
        $coTeacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $dropped = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Preserved online course', 'description' => 'Original description', 'status' => 'published']);
        $lesson = $course->lessons()->create(['title' => 'Existing lesson', 'body' => 'Keep this original content', 'position' => 1]);
        $assignment = $course->assignments()->create(['title' => 'Original work', 'instructions' => 'Keep work', 'due_at' => now()->addDay(), 'max_marks' => 20]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'Existing private work', 'submitted_at' => now(), 'is_late' => false, 'status' => 'graded', 'grade' => 18]);
        $university = DB::table('universities')->insertGetId(['name' => 'Old organization', 'code' => 'OLD']);
        $campus = DB::table('campuses')->insertGetId(['university_id' => $university, 'name' => 'Old campus']);
        $faculty = DB::table('faculties')->insertGetId(['university_id' => $university, 'name' => 'Old faculty']);
        $department = DB::table('departments')->insertGetId(['campus_id' => $campus, 'faculty_id' => $faculty, 'name' => 'Old department']);
        $program = DB::table('programs')->insertGetId(['department_id' => $department, 'name' => 'Old program', 'code' => 'OLD']);
        $curriculum = DB::table('curricula')->insertGetId(['program_id' => $program, 'version' => 'old', 'effective_from' => '2026-01-01', 'max_credits' => 20, 'policy_label' => 'Demonstration']);
        $term = DB::table('academic_terms')->insertGetId(['name' => 'Old term', 'academic_year' => '2026', 'registration_opens_at' => now(), 'add_drop_closes_at' => now(), 'withdrawal_closes_at' => now()]);
        $catalog = DB::table('catalog_courses')->insertGetId(['code' => 'OLD', 'title' => 'Old catalog entry', 'credits' => 3]);
        $offering = DB::table('course_offerings')->insertGetId(['program_id' => $program, 'academic_term_id' => $term, 'catalog_course_id' => $catalog]);
        $section = DB::table('sections')->insertGetId(['course_offering_id' => $offering, 'name' => 'A', 'capacity' => 20, 'learning_course_id' => $course->id]);
        foreach ([[$student, 'registered'], [$dropped, 'dropped']] as [$user, $status]) {
            $enrollment = DB::table('program_enrollments')->insertGetId(['user_id' => $user->id, 'curriculum_id' => $curriculum, 'cohort' => 'Old cohort']);
            DB::table('registrations')->insert(['program_enrollment_id' => $enrollment, 'course_offering_id' => $offering, 'section_id' => $section, 'status' => $status, 'policy_snapshot' => '{}']);
        }
        DB::table('section_instructors')->insert(['section_id' => $section, 'user_id' => $coTeacher->id, 'active' => true]);
        $migration = require database_path('migrations/2026_09_12_000001_migrate_to_online_lms.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', ['course_id' => $course->id, 'user_id' => $student->id]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $dropped->id]);
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'body' => 'Existing private work', 'grade' => 18]);
        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'body' => 'Keep this original content']);
        foreach (['universities', 'sections', 'registrations', 'role_assignments', 'rooms', 'attendance_records', 'timetable_notifications', 'academic_write_locks'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must be removed');
        }
        $this->actingAs($student)->get(route('courses.show', $course))->assertOk()->assertSee('Keep this original content');
        $this->actingAs($coTeacher)->get(route('courses.edit', $course))->assertOk();
        $this->actingAs($dropped)->get(route('courses.show', $course))->assertForbidden();
    }

    public function test_code_backfill_preserves_existing_records(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Existing course', 'description' => 'Preserved', 'status' => 'published']);
        $migration = require database_path('migrations/2026_09_12_182116_add_unique_code_to_courses.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'code' => 'EL-'.str_pad((string) $course->id, 6, '0', STR_PAD_LEFT), 'description' => 'Preserved']);
        $this->assertSame(0, DB::table('courses')->whereNull('code')->count());
    }
}
