<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function assessment(): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Assessment course', 'description' => 'Test', 'status' => 'published']);
        Enrollment::create(['course_id' => $course->id, 'user_id' => $student->id]);
        $assignment = $course->assignments()->create(['title' => 'Analysis', 'instructions' => 'Explain the evidence', 'due_at' => now()->addHour(), 'max_marks' => 20]);

        return [$teacher, $student, $assignment];
    }

    public function test_rubric_scoring_is_bounded_audited_and_locked_after_submission(): void
    {
        [$teacher, $student, $assignment] = $this->assessment();
        $criteria = [['label' => 'Evidence', 'max_marks' => 12], ['label' => 'Clarity', 'max_marks' => 8]];
        $this->actingAs($teacher)->post(route('assignments.rubric', $assignment), ['version' => 0, 'criteria' => $criteria])->assertRedirect();
        $this->post(route('assignments.rubric', $assignment), ['version' => 0, 'criteria' => $criteria])->assertConflict();
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'My answer'])->assertRedirect();
        $submission = Submission::firstOrFail();
        $this->actingAs($teacher)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Evidence');
        $this->post(route('assignments.rubric', $assignment), ['version' => 1, 'criteria' => $criteria])->assertConflict();
        $payload = ['version' => 0, 'scores' => [1 => 7.25, 0 => 10.5], 'grade' => 999, 'reason' => 'Initial review', 'feedback' => 'Good evidence'];
        $this->patch(route('submissions.grade', $submission), array_replace($payload, ['scores' => [13, 8]]))->assertSessionHasErrors('scores.0');
        $this->patch(route('submissions.grade', $submission), $payload)->assertRedirect();
        $this->assertEquals(17.75, $submission->fresh()->grade);
        $this->assertEquals([10.5, 7.25], $submission->fresh()->rubric_scores);
        $this->patch(route('submissions.grade', $submission), $payload)->assertConflict();
        $this->assertDatabaseCount('assessment_grade_changes', 1);
        $this->patch(route('submissions.grade', $submission), array_replace($payload, ['version' => 1, 'scores' => [12, 8], 'reason' => 'Moderation correction']))->assertRedirect();
        $this->assertDatabaseCount('assessment_grade_changes', 2);
        $this->get(route('assignments.show', $assignment))->assertOk()->assertSee('17.75')->assertSee('Moderation correction');
        $this->post(route('submissions.publish', $submission), ['version' => 2, 'confirm' => 1, 'reason' => 'Reviewed result'])->assertRedirect();
        $this->actingAs($student)->get(route('assignments.show', $assignment))->assertOk()->assertSee('Good evidence')->assertDontSee('Moderation correction');
    }

    public function test_extensions_are_private_monotonic_and_update_lateness(): void
    {
        [$teacher, $student, $assignment] = $this->assessment();
        $other = User::factory()->create(['role' => 'student']);
        Enrollment::create(['course_id' => $assignment->course_id, 'user_id' => $other->id]);
        $assignment->update(['due_at' => now()->subHour()]);
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'Late answer'])->assertSessionHasErrors('deadline');
        $this->assertDatabaseCount('submissions', 0);
        $payload = ['user_id' => $student->id, 'due_at' => now()->addDay()->format('Y-m-d H:i:s'), 'reason' => 'Approved individual extension'];
        $this->post(route('assignments.extend', $assignment), $payload)->assertForbidden();
        $this->actingAs($teacher)->post(route('assignments.extend', $assignment), array_replace($payload, ['user_id' => $teacher->id]))->assertUnprocessable();
        $this->post(route('assignments.extend', $assignment), $payload)->assertRedirect();
        $this->assertDatabaseCount('submissions', 0);
        $this->post(route('assignments.extend', $assignment), $payload)->assertSessionHasErrors('due_at');
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'On time now'])->assertRedirect();
        $this->assertFalse(Submission::firstOrFail()->is_late);
        $this->get(route('assignments.show', $assignment))->assertOk()->assertSee('Approved individual extension');
        $this->actingAs($other)->get(route('assignments.show', $assignment))->assertOk()->assertDontSee('Approved individual extension')->assertDontSee('On time now');
    }

    public function test_rubric_total_and_manual_grade_validation_and_resubmission_version(): void
    {
        [$teacher, $student, $assignment] = $this->assessment();
        $this->actingAs($teacher)->post(route('assignments.rubric', $assignment), ['version' => 0, 'criteria' => [['label' => 'Only', 'max_marks' => 19]]])->assertSessionHasErrors('criteria');
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'First answer'])->assertRedirect();
        $submission = Submission::firstOrFail();
        $this->post(route('assignments.submit', $assignment), ['body' => 'Replacement'])->assertRedirect();
        $payload = ['version' => 0, 'grade' => 15, 'reason' => 'Review'];
        $this->actingAs($teacher)->patch(route('submissions.grade', $submission), $payload)->assertConflict();
        $this->patch(route('submissions.grade', $submission), array_replace($payload, ['version' => 1, 'grade' => 21]))->assertSessionHasErrors('grade');
        $this->patch(route('submissions.grade', $submission), array_replace($payload, ['version' => 1, 'grade' => 15.123]))->assertSessionHasErrors('grade');
        $this->patch(route('submissions.grade', $submission), array_replace($payload, ['version' => 1]))->assertRedirect();
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'grade' => 15, 'grade_version' => 2]);
        $this->assertSame('Review', DB::table('assessment_grade_changes')->value('reason'));
    }
}
