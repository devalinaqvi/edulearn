<?php

namespace Tests\Feature;

use App\Actions\ContentLifecycle;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * An export makes deletion a decision that can be taken back, and the retention window keeps
 * Trash from growing without bound. Neither may weaken the rule that learner history survives.
 */
class CourseExportAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['code' => 'EL-EXP', 'instructor_id' => $this->teacher->id, 'title' => 'Export course', 'description' => 'Online', 'status' => 'published']);
        $this->course->enrollments()->create(['user_id' => $this->student->id]);
    }

    /** @return array<string, string> entry name => contents */
    private function readArchive(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-export-');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download is not a readable zip archive.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $entries;
    }

    public function test_an_export_contains_the_teaching_content(): void
    {
        Storage::fake('local');
        $this->course->lessons()->create(['title' => 'First lesson', 'body' => '<p>Lesson body</p>', 'body_format' => 'html', 'position' => 1]);
        $this->course->assignments()->create(['title' => 'An assignment', 'instructions' => 'Do the work', 'due_at' => now()->addWeek(), 'max_marks' => 20]);
        $this->actingAs($this->teacher)->post(route('materials.store', $this->course), [
            'title' => 'Reader', 'file' => UploadedFile::fake()->createWithContent('reader.txt', 'The readable content'),
        ])->assertRedirect();

        $response = $this->get(route('courses.export', $this->course))->assertOk();
        $entries = $this->readArchive($response->streamedContent());

        $this->assertArrayHasKey('course.json', $entries);
        $this->assertArrayHasKey('README.txt', $entries);
        $manifest = json_decode($entries['course.json'], true);

        $this->assertSame('Export course', $manifest['course']['title']);
        $this->assertSame('First lesson', $manifest['lessons'][0]['title']);
        $this->assertSame('An assignment', $manifest['assignments'][0]['title']);

        // The material file itself travels with the manifest, not just its name.
        $material = collect($entries)->keys()->first(fn ($name) => str_starts_with($name, 'materials/'));
        $this->assertNotNull($material);
        $this->assertSame('The readable content', $entries[$material]);
    }

    public function test_an_export_carries_no_learner_data(): void
    {
        Storage::fake('local');
        $assignment = $this->course->assignments()->create(['title' => 'Work', 'instructions' => 'Do it', 'due_at' => now()->addWeek(), 'max_marks' => 10]);
        $this->actingAs($this->student)->post(route('assignments.submit', $assignment), ['body' => 'MY PRIVATE ANSWER'])->assertRedirect();

        $entries = $this->readArchive($this->actingAs($this->teacher)->get(route('courses.export', $this->course))->assertOk()->streamedContent());
        $everything = implode("\n", $entries);

        // An export is a file that gets emailed and forgotten; it must not move a student record.
        $this->assertStringNotContainsString('MY PRIVATE ANSWER', $everything);
        $this->assertStringNotContainsString($this->student->email, $everything);
        $this->assertStringNotContainsString($this->student->name, $everything);

        $manifest = json_decode($entries['course.json'], true);
        foreach (['enrollments', 'submissions', 'attempts', 'grades', 'learners'] as $absent) {
            $this->assertArrayNotHasKey($absent, $manifest);
        }
    }

    public function test_only_course_staff_may_export(): void
    {
        $outsider = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($outsider)->get(route('courses.export', $this->course))->assertForbidden();
        $this->actingAs($this->student)->get(route('courses.export', $this->course))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('courses.export', $this->course))->assertOk();
    }

    public function test_the_export_link_is_offered_to_staff_only(): void
    {
        $this->actingAs($this->teacher)->get(route('courses.show', $this->course))->assertOk()->assertSee('Export a copy');
        $this->actingAs($this->student)->get(route('courses.show', $this->course))->assertOk()->assertDontSee('Export a copy');
    }

    public function test_the_purge_removes_long_archived_content_that_nothing_depends_on(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Orphan', 'body' => 'Body', 'position' => 1])->refresh();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();
        Lesson::whereKey($lesson->id)->update(['archived_at' => now()->subDays(120)]);

        $this->artisan('lms:purge-trash', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
        // Attributed to the system, because no person asked for this one.
        $entry = DB::table('content_lifecycle_changes')->where('action', 'purged')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('Orphan', $entry->subject_title);
    }

    public function test_the_purge_never_removes_content_carrying_learner_history(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Studied', 'body' => 'Body', 'position' => 1])->refresh();
        $this->actingAs($this->student)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();

        // Far beyond any retention window: age must never override a dependency.
        Lesson::whereKey($lesson->id)->update(['archived_at' => now()->subYears(5)]);

        $this->artisan('lms:purge-trash', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'status' => 'archived']);
        $this->assertDatabaseHas('lesson_completions', ['lesson_id' => $lesson->id]);
        $this->assertSame(0, DB::table('content_lifecycle_changes')->where('action', 'purged')->count());
    }

    public function test_the_purge_leaves_recently_archived_and_active_content_alone(): void
    {
        $recent = $this->course->lessons()->create(['title' => 'Recent', 'body' => 'Body', 'position' => 1])->refresh();
        $active = $this->course->lessons()->create(['title' => 'Active', 'body' => 'Body', 'position' => 2])->refresh();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $recent), ['version' => $recent->version, 'action' => 'archive'])->assertRedirect();

        $this->artisan('lms:purge-trash', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseHas('lessons', ['id' => $recent->id]);
        $this->assertDatabaseHas('lessons', ['id' => $active->id, 'status' => 'active']);
    }

    public function test_a_dry_run_reports_without_removing_anything(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Orphan', 'body' => 'Body', 'position' => 1])->refresh();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();
        Lesson::whereKey($lesson->id)->update(['archived_at' => now()->subDays(120)]);

        $this->artisan('lms:purge-trash', ['--days' => 90, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
        $this->assertSame(0, DB::table('content_lifecycle_changes')->where('action', 'purged')->count());
    }

    public function test_the_purge_reports_what_it_kept_and_why(): void
    {
        $kept = $this->course->lessons()->create(['title' => 'Studied', 'body' => 'Body', 'position' => 1])->refresh();
        $this->actingAs($this->student)->post(route('lessons.complete', $kept), ['completed' => '1'])->assertRedirect();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $kept), ['version' => $kept->version, 'action' => 'archive'])->assertRedirect();
        Lesson::whereKey($kept->id)->update(['archived_at' => now()->subDays(120)]);

        $result = app(ContentLifecycle::class)->purgeArchived(90);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['retained']);
    }

    public function test_trash_explains_the_retention_window(): void
    {
        $this->actingAs($this->teacher)->get(route('trash'))->assertOk()
            ->assertSee('removed automatically after '.config('lms.trash_retention_days').' days');
    }
}
