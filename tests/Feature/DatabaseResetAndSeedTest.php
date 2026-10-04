<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MinimalLoginSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseResetAndSeedTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
    }

    public function test_erasing_removes_application_data_but_keeps_the_schema_and_migration_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertGreaterThan(0, Course::count());

        $tablesBefore = $this->tables();
        $migrationsBefore = DB::table('migrations')->count();
        $this->assertGreaterThan(0, $migrationsBefore);

        $this->artisan('lms:erase', ['--force' => true])->assertSuccessful();

        $this->assertSame($tablesBefore, $this->tables(), 'Erasing data must not drop or add tables.');
        $this->assertSame($migrationsBefore, DB::table('migrations')->count(), 'Migration history is schema state, not application data.');

        foreach (['users', 'courses', 'lessons', 'assignments', 'quizzes', 'enrollments', 'submissions', 'announcements', 'materials', 'study_notes', 'quiz_attempts', 'assessment_grade_changes'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' should have been erased.');
        }
    }

    public function test_erasing_restores_the_records_the_application_cannot_run_without(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('lms:erase', ['--force' => true])->assertSuccessful();

        // Without row 1 every serialized mutation refuses to proceed, so an erase that dropped it
        // would leave an installation that cannot be written to at all.
        $this->assertTrue(DB::table('lms_write_locks')->where('id', 1)->exists());
        $this->assertSame('EduLearn', DB::table('settings')->where('key', 'site_name')->value('value'));
    }

    public function test_erasing_leaves_foreign_key_enforcement_switched_on(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('lms:erase', ['--force' => true])->assertSuccessful();

        // Proves the delete order did the work, rather than constraints having been disabled.
        $this->expectException(QueryException::class);
        DB::table('lessons')->insert(['course_id' => 99999, 'title' => 'Orphan', 'body' => 'Orphan', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_whole_developer_workflow_runs_end_to_end(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('lms:erase', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, User::count());

        $this->seed(MinimalLoginSeeder::class);
        $this->post('/login', ['email' => 'admin@acumen.test', 'password' => MinimalLoginSeeder::PASSWORD])->assertRedirect(route('dashboard.admin'));
        $this->post(route('logout'));

        $this->seed(DatabaseSeeder::class);
        $this->post('/login', ['email' => 'student@acumen.test', 'password' => MinimalLoginSeeder::PASSWORD])->assertRedirect(route('dashboard.student'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertGreaterThan(0, Course::where('status', 'published')->count());
    }

    public function test_the_complete_seed_represents_the_states_this_schema_supports(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Course::where('status', 'draft')->count());
        $this->assertSame(1, Course::where('status', 'archived')->count());

        $empty = Course::where('code', 'EL-EMPTY')->sole();
        $this->assertSame('published', $empty->status);
        $this->assertSame(0, $empty->lessons()->count());

        // Archival withdraws access without destroying the enrolment record behind it.
        $this->assertGreaterThan(0, Course::where('code', 'EL-ARCHIVE')->sole()->enrollments()->count());

        $this->assertTrue(Announcement::whereNull('published_at')->exists(), 'a draft announcement');
        $this->assertTrue(Announcement::where('published_at', '>', now())->exists(), 'a scheduled announcement');
        $this->assertTrue(Announcement::whereNotNull('published_at')->where('published_at', '<=', now())->exists(), 'a live announcement');

        $this->assertDatabaseHas('submissions', ['status' => 'graded']);
        $this->assertTrue(DB::table('result_publications')->exists(), 'a published result');
        $this->assertTrue(DB::table('quiz_attempts')->whereNotNull('submitted_at')->exists(), 'a finalized quiz attempt');
    }

    public function test_the_complete_seed_creates_no_orphans_and_is_rerunnable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $courses = Course::count();
        $users = User::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($courses, Course::count(), 'Re-seeding must not duplicate courses.');
        $this->assertSame($users, User::count(), 'Re-seeding must not duplicate accounts.');

        $this->assertSame(0, DB::table('lessons')->leftJoin('courses', 'courses.id', '=', 'lessons.course_id')->whereNull('courses.id')->count());
        $this->assertSame(0, DB::table('enrollments')->leftJoin('courses', 'courses.id', '=', 'enrollments.course_id')->whereNull('courses.id')->count());
        $this->assertSame(0, DB::table('submissions')->leftJoin('assignments', 'assignments.id', '=', 'submissions.assignment_id')->whereNull('assignments.id')->count());
    }

    public function test_a_scheduled_announcement_from_the_seed_is_withheld_until_its_time(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::where('role', 'student')->sole();

        $scheduled = Announcement::where('published_at', '>', now())->firstOrFail();
        $this->assertFalse(Announcement::visibleTo($student)->whereKey($scheduled->id)->exists());

        $this->travelTo(now()->addMonth());
        $this->assertTrue(Announcement::visibleTo($student)->whereKey($scheduled->id)->exists());
    }
}
