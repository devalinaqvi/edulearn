<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Assignments carry the same draft/published/archived lifecycle as the rest of the course, and
 * the same deletion rule: destroy only what nobody has worked on.
 */
class AssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $learner;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->learner = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['code' => 'EL-ALC', 'instructor_id' => $this->teacher->id, 'title' => 'Lifecycle course', 'description' => 'Online', 'status' => 'published']);
        $this->course->enrollments()->create(['user_id' => $this->learner->id]);
    }

    private function createAssignment(array $overrides = []): Assignment
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->course), array_merge([
            'title' => 'Essay', 'instructions' => 'Write the essay.',
            'due_at' => DisplayTime::forInput(now()->addWeek()), 'max_marks' => 20,
        ], $overrides))->assertRedirect();

        return Assignment::where('course_id', $this->course->id)->latest('id')->firstOrFail();
    }

    private function setStatus(Assignment $assignment, string $status, array $extra = []): TestResponse
    {
        return $this->actingAs($this->teacher)->post(route('assignments.status', $assignment), array_merge([
            'version' => $assignment->fresh()->version, 'status' => $status,
        ], $extra));
    }

    public function test_a_new_assignment_starts_as_a_draft_and_is_invisible_to_learners(): void
    {
        $assignment = $this->createAssignment();
        $this->assertTrue($assignment->isDraft());

        $this->actingAs($this->learner)->get(route('assignments.show', $assignment))->assertNotFound();
        $this->get(route('courses.show', $this->course))->assertOk()->assertDontSee('Essay');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Essay');
    }

    public function test_publishing_issues_it_to_learners(): void
    {
        $assignment = $this->createAssignment();

        $this->setStatus($assignment, 'published')->assertRedirect();

        $this->assertTrue($assignment->fresh()->isPublished());
        $this->assertNotNull($assignment->fresh()->published_at);
        $this->actingAs($this->learner)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Essay');
        $this->get(route('courses.show', $this->course))->assertOk()->assertSee('Essay');
    }

    public function test_a_draft_cannot_be_submitted_to(): void
    {
        $assignment = $this->createAssignment();

        $this->actingAs($this->learner)->post(route('assignments.submit', $assignment), ['body' => 'Early work'])->assertNotFound();

        $this->assertSame(0, DB::table('submissions')->count());
    }

    public function test_archiving_withdraws_it_while_keeping_submitted_work(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published')->assertRedirect();
        $this->actingAs($this->learner)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();

        $this->setStatus($assignment, 'archived')->assertRedirect();

        $this->assertTrue($assignment->fresh()->isArchived());
        $this->assertDatabaseHas('submissions', ['assignment_id' => $assignment->id, 'body' => 'My answer']);

        // Withdrawn from the learner, still reachable by the staff who must grade it.
        $this->actingAs($this->learner)->get(route('assignments.show', $assignment))->assertNotFound();
        $this->post(route('assignments.submit', $assignment), ['body' => 'Late work'])->assertNotFound();
        $this->actingAs($this->teacher)->get(route('assignments.show', $assignment))->assertOk();
    }

    public function test_an_archived_assignment_can_be_restored(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published')->assertRedirect();
        $this->setStatus($assignment, 'archived')->assertRedirect();

        $this->setStatus($assignment, 'published')->assertRedirect();

        $this->assertTrue($assignment->fresh()->isPublished());
        $this->assertNull($assignment->fresh()->archived_at);
        $this->actingAs($this->learner)->get(route('assignments.show', $assignment))->assertOk();
    }

    public function test_issued_work_cannot_be_returned_to_draft(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published')->assertRedirect();
        $this->actingAs($this->learner)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();

        // Returning it to draft would leave submissions attached to something never issued.
        $this->setStatus($assignment, 'draft')->assertSessionHasErrors('status');

        $this->assertTrue($assignment->fresh()->isPublished());
    }

    public function test_an_assignment_nobody_submitted_to_can_be_deleted_permanently(): void
    {
        $assignment = $this->createAssignment();

        $this->actingAs($this->teacher)->delete(route('assignments.destroy', $assignment), [
            'version' => $assignment->version, 'confirm' => '1', 'reason' => 'Created in error.',
        ])->assertRedirect(route('courses.show', $this->course));

        $this->assertDatabaseMissing('assignments', ['id' => $assignment->id]);
        $entry = DB::table('content_lifecycle_changes')->where('action', 'deleted')->sole();
        $this->assertSame('assignment', $entry->subject_type);
        $this->assertSame('Created in error.', $entry->reason);
    }

    public function test_an_assignment_with_submitted_work_is_archived_instead_of_deleted(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published')->assertRedirect();
        $this->actingAs($this->learner)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();

        $this->actingAs($this->teacher)->delete(route('assignments.destroy', $assignment), [
            'version' => $assignment->fresh()->version, 'confirm' => '1',
        ])->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'assignment submissions'));

        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'status' => 'archived']);
        $this->assertDatabaseHas('submissions', ['assignment_id' => $assignment->id]);
    }

    public function test_deletion_requires_confirmation_and_a_current_version(): void
    {
        $assignment = $this->createAssignment();

        $this->actingAs($this->teacher)->delete(route('assignments.destroy', $assignment), ['version' => $assignment->version])
            ->assertSessionHasErrors('confirm');
        $this->delete(route('assignments.destroy', $assignment), ['version' => 99, 'confirm' => '1'])
            ->assertSessionHasErrors('conflict');

        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
    }

    public function test_only_course_staff_may_change_state_or_delete(): void
    {
        $assignment = $this->createAssignment();
        $outsider = User::factory()->create(['role' => 'instructor']);

        foreach ([$outsider, $this->learner] as $actor) {
            $this->actingAs($actor)->post(route('assignments.status', $assignment), ['version' => $assignment->version, 'status' => 'published'])->assertForbidden();
            $this->delete(route('assignments.destroy', $assignment), ['version' => $assignment->version, 'confirm' => '1'])->assertForbidden();
        }

        $this->assertTrue($assignment->fresh()->isDraft());
    }

    public function test_archived_assignments_appear_in_trash_for_their_staff_only(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published')->assertRedirect();
        $this->setStatus($assignment, 'archived')->assertRedirect();
        $outsider = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($this->teacher)->get(route('trash'))->assertOk()
            ->assertSee('Archived assignments')->assertSee('Essay');

        $this->actingAs($outsider)->get(route('trash'))->assertOk()->assertDontSee('Essay');
    }

    public function test_instructions_accept_formatting_and_are_sanitized_on_the_way_in(): void
    {
        $assignment = $this->createAssignment([
            'instructions' => '<h2>Brief</h2><p>Write <strong>clearly</strong>.</p><script>alert(1)</script>',
            'instructions_format' => 'html',
        ]);

        $this->assertSame('html', $assignment->instructions_format);
        $this->assertStringContainsString('<strong>clearly</strong>', $assignment->instructions);
        $this->assertStringNotContainsString('<script', $assignment->instructions);
        $this->assertStringNotContainsString('alert', $assignment->instructions);
    }

    public function test_formatted_instructions_render_as_markup_while_legacy_text_stays_escaped(): void
    {
        $rich = $this->createAssignment(['instructions' => '<p>Rendered <em>brief</em></p>', 'instructions_format' => 'html']);
        $plain = $this->createAssignment(['title' => 'Plain', 'instructions' => "Line one\n\nA <not a tag> example"]);
        $this->setStatus($rich, 'published');
        $this->setStatus($plain, 'published');

        $this->actingAs($this->teacher)->get(route('assignments.show', $rich))->assertOk()->assertSee('<em>brief</em>', false);
        $this->get(route('assignments.show', $plain))->assertOk()->assertSee('&lt;not a tag&gt;', false);
    }

    public function test_instructions_that_are_only_markup_are_rejected(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.store', $this->course), [
            'title' => 'Empty', 'instructions' => '<script>alert(1)</script>', 'instructions_format' => 'html',
            'due_at' => DisplayTime::forInput(now()->addWeek()), 'max_marks' => 10,
        ])->assertSessionHasErrors('instructions');

        $this->assertSame(0, Assignment::where('course_id', $this->course->id)->count());
    }

    public function test_editing_also_sanitizes_the_brief(): void
    {
        $assignment = $this->createAssignment();

        $this->actingAs($this->teacher)->patch(route('assignments.update', $assignment), [
            'version' => $assignment->version, 'title' => 'Essay',
            'instructions' => '<p>Revised</p><iframe src="https://evil.test"></iframe>',
            'instructions_format' => 'html',
            'due_at' => DisplayTime::forInput($assignment->due_at), 'max_marks' => 20,
        ])->assertRedirect();

        $assignment->refresh();
        $this->assertStringContainsString('Revised', $assignment->instructions);
        $this->assertStringNotContainsString('<iframe', $assignment->instructions);
    }

    public function test_both_assignment_forms_offer_the_editor(): void
    {
        $assignment = $this->createAssignment();

        $this->actingAs($this->teacher)->get(route('courses.show', $this->course))->assertOk()
            ->assertSee('new-assignment-instructions', false);
        $this->get(route('assignments.show', $assignment))->assertOk()
            ->assertSee('assignment-instructions-'.$assignment->id, false)
            ->assertSee('name="instructions_format"', false);
    }

    public function test_state_changes_are_recorded_in_the_history(): void
    {
        $assignment = $this->createAssignment();
        $this->setStatus($assignment, 'published', ['reason' => 'Ready for the cohort.'])->assertRedirect();
        $this->setStatus($assignment, 'archived')->assertRedirect();

        $entries = DB::table('content_lifecycle_changes')->where('subject_type', 'assignment')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('published', $entries[0]->action);
        $this->assertSame('Ready for the cohort.', $entries[0]->reason);
        $this->assertSame('archived', $entries[1]->action);
        $this->assertSame('Essay', $entries[1]->subject_title);
    }

    public function test_existing_assignments_keep_being_visible_after_the_migration(): void
    {
        // The column defaults to published precisely so live coursework is not withdrawn by a
        // deployment. A row created without a status must behave as issued.
        $assignment = $this->course->assignments()->create([
            'title' => 'Pre-existing', 'instructions' => 'Written before the lifecycle existed',
            'due_at' => now()->addWeek(), 'max_marks' => 10,
        ])->refresh();

        $this->assertTrue($assignment->isPublished());
        $this->actingAs($this->learner)->get(route('assignments.show', $assignment))->assertOk();
    }
}
