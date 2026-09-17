<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacements_retain_private_history_and_duplicate_requests_are_idempotent(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Practice', 'description' => 'Online', 'status' => 'published']);
        $assignment = $course->assignments()->create(['title' => 'Work', 'instructions' => 'Respond', 'due_at' => now()->addHour(), 'max_marks' => 20]);
        $this->actingAs($student)->post(route('courses.enroll', $course))->assertRedirect();
        $this->post(route('assignments.submit', $assignment), ['body' => 'First evidence', 'file' => UploadedFile::fake()->createWithContent('work.txt', 'First attachment')])->assertRedirect();
        $submission = Submission::firstOrFail();
        $oldPath = $submission->path;
        $this->post(route('assignments.submit', $assignment), ['body' => 'Revised evidence', 'version' => 0])->assertRedirect();
        $this->post(route('assignments.submit', $assignment), ['body' => 'Revised evidence', 'version' => 0])->assertRedirect();
        $this->assertDatabaseCount('submissions', 1);
        $this->assertDatabaseCount('submission_revisions', 1);
        Storage::disk('local')->assertExists($oldPath);
        $revision = DB::table('submission_revisions')->first();
        $this->get(route('submissions.revisions.download', $revision->id))->assertOk();
        $this->post(route('assignments.submit', $assignment), ['body' => 'Stale overwrite', 'version' => 0])->assertConflict();
        $this->get(route('assignments.show', $assignment))->assertSee('First evidence')->assertSee('Revised evidence');
        $this->actingAs($other)->post(route('courses.enroll', $course))->assertRedirect();
        $this->get(route('submissions.revisions.download', $revision->id))->assertForbidden();
        $this->get(route('assignments.show', $assignment))->assertDontSee('First evidence');
        $this->actingAs($teacher)->get(route('submissions.revisions.download', $revision->id))->assertOk();
        $this->travelTo($assignment->due_at);
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'Too late', 'file' => UploadedFile::fake()->createWithContent('late.txt', 'Late')])->assertSessionHasErrors('deadline');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame('Revised evidence', $submission->fresh()->body);
        $this->get(route('assignments.show', $assignment))->assertSee('Submissions and replacements are closed.')->assertDontSee('Replace submission');
    }
}
