<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_grades_are_private_and_corrections_require_confirmed_republication(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Grading', 'description' => 'Online', 'status' => 'published']);
        $assignment = $course->assignments()->create(['title' => 'Practice', 'instructions' => 'Respond', 'due_at' => now()->addDay(), 'max_marks' => 20]);
        $this->actingAs($student)->post(route('courses.enroll', $course))->assertRedirect();
        $this->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();
        $submission = Submission::firstOrFail();
        $this->actingAs($teacher)->patch(route('submissions.grade', $submission), ['version' => 0, 'grade' => 12.5, 'feedback' => 'Initial private feedback', 'reason' => 'Private staff discussion'])->assertRedirect();
        $this->actingAs($student)->get(route('assignments.show', $assignment))->assertOk()->assertDontSee('Initial private feedback')->assertDontSee('Private staff discussion')->assertSee('Results are awaiting publication');
        $payload = ['version' => 1, 'reason' => 'Reviewed', 'confirm' => 1];
        $this->post(route('submissions.publish', $submission), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'instructor']))->post(route('submissions.publish', $submission), $payload)->assertForbidden();
        $this->actingAs($teacher)->post(route('submissions.publish', $submission), ['version' => 1, 'reason' => 'Reviewed'])->assertSessionHasErrors('confirm');
        $this->post(route('submissions.publish', $submission), array_replace($payload, ['version' => 0]))->assertConflict();
        $this->post(route('submissions.publish', $submission), $payload)->assertRedirect();
        $this->post(route('submissions.publish', $submission), $payload)->assertRedirect();
        $this->assertDatabaseCount('result_publications', 1);
        $this->patch(route('submissions.grade', $submission), ['version' => 1, 'grade' => 15, 'feedback' => 'Corrected feedback', 'reason' => 'Moderation'])->assertRedirect();
        $this->actingAs($student)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Initial private feedback')->assertDontSee('Corrected feedback')->assertDontSee('Moderation');
        $this->actingAs($teacher)->post(route('submissions.publish', $submission), array_replace($payload, ['version' => 2]))->assertRedirect();
        $this->assertDatabaseCount('result_publications', 2);
        $this->actingAs($student)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Corrected feedback')->assertDontSee('Initial private feedback');
        $this->actingAs($other)->post(route('courses.enroll', $course))->assertRedirect();
        $this->get(route('assignments.show', $assignment))->assertOk()->assertDontSee('Corrected feedback');
    }
}
