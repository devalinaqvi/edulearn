<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rejected write must not cost somebody their typing.
 *
 * Optimistic concurrency still refuses a stale save; what changed is the answer. Instead of a
 * full page replacing the form, the person is returned to it with what they wrote intact, the
 * same way a failed validation behaves.
 */
class StaleFormRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Assignment} */
    private function scenario(): array
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-STALE', 'instructor_id' => $teacher->id, 'title' => 'Stale course', 'description' => 'Online', 'status' => 'published']);
        $assignment = $course->assignments()->create([
            'title' => 'Original', 'instructions' => 'Original instructions',
            'due_at' => now()->addWeek(), 'max_marks' => 10,
        ])->refresh();

        return [$teacher, $assignment];
    }

    public function test_a_stale_save_returns_the_person_to_their_work_rather_than_an_error_page(): void
    {
        [$teacher, $assignment] = $this->scenario();
        $this->actingAs($teacher)->get(route('assignments.show', $assignment))->assertOk();

        // Somebody else saves first.
        $this->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'Saved by a colleague', 'instructions' => 'Theirs',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertRedirect();

        $longAnswer = 'A carefully written set of instructions that would be infuriating to lose.';
        $response = $this->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'My edit', 'instructions' => $longAnswer,
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ]);

        $response->assertRedirect()->assertSessionHasErrors('conflict');
        // The whole point of the change: the text comes back with them.
        $response->assertSessionHasInput('instructions', $longAnswer);
        $response->assertSessionHasInput('title', 'My edit');

        $this->assertSame('Saved by a colleague', $assignment->fresh()->title, 'The stale write is still refused.');
    }

    public function test_the_conflict_is_explained_in_the_words_the_action_chose(): void
    {
        [$teacher, $assignment] = $this->scenario();

        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'First', 'instructions' => 'First',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertRedirect();

        $response = $this->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'Second', 'instructions' => 'Second',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ]);

        $response->assertSessionHasErrors(['conflict' => 'This assignment changed. Reload before saving.']);
    }

    public function test_a_json_caller_still_receives_the_conflict_status(): void
    {
        [$teacher, $assignment] = $this->scenario();
        $this->actingAs($teacher)->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'First', 'instructions' => 'First',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertRedirect();

        // A redirect is the right answer for a browser form, not for a machine.
        $this->patchJson(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'Second', 'instructions' => 'Second',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertStatus(409);
    }

    public function test_the_returned_form_shows_the_conflict_and_the_recovered_text(): void
    {
        [$teacher, $assignment] = $this->scenario();
        $this->actingAs($teacher)->get(route('assignments.show', $assignment))->assertOk();
        $this->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'Taken', 'instructions' => 'Taken',
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertRedirect();

        $recovered = 'Text the author must not have to retype';
        $this->followingRedirects()->patch(route('assignments.update', $assignment), [
            'version' => 0, 'title' => 'Mine', 'instructions' => $recovered,
            'due_at' => $assignment->due_at->format('Y-m-d\TH:i'), 'max_marks' => 10,
        ])->assertOk()
            ->assertSee('toast toast-error', false)
            ->assertSee('This assignment changed')
            ->assertSee($recovered, false);
    }
}
