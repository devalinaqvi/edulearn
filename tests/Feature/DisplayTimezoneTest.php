<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Storage stays UTC; interpretation happens at the form and display edges.
 *
 * Asia/Karachi is UTC+5 and does not observe daylight saving, so 15:00 local is 10:00 stored.
 * Nothing here asserts a hard-coded offset arithmetic: the conversions go through the timezone
 * database, and these tests only pin the observable round trip.
 */
class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'instructor']);
    }

    private function course(User $teacher): Course
    {
        return Course::create(['code' => 'EL-TZ', 'instructor_id' => $teacher->id, 'title' => 'Timezone course', 'description' => 'Online', 'status' => 'published']);
    }

    public function test_the_configured_zone_is_reported_for_display(): void
    {
        $this->assertSame('Asia/Karachi', DisplayTime::zone());
        $this->assertSame('PKT', DisplayTime::abbreviation());
    }

    public function test_a_local_wall_clock_entry_is_stored_as_the_matching_utc_instant(): void
    {
        $this->assertSame('2026-10-10 10:00:00', DisplayTime::toUtcString('2026-10-10T15:00'));
        $this->assertSame('2026-10-10T15:00', DisplayTime::forInput(Carbon::parse('2026-10-10 10:00:00', 'UTC')));
        $this->assertSame('Oct 10, 2026 · 15:00 PKT', DisplayTime::format(Carbon::parse('2026-10-10 10:00:00', 'UTC')));
    }

    public function test_a_quiz_window_typed_locally_is_persisted_in_utc_and_read_back_locally(): void
    {
        $teacher = $this->staff();
        $course = $this->course($teacher);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Timezone quiz', 'instructions' => 'Instructions.',
            'opens_at' => '2026-11-02T09:00',
            'closes_at' => '2026-11-02T17:30',
            'duration_minutes' => 30,
        ])->assertRedirect();

        $quiz = DB::table('quizzes')->where('course_id', $course->id)->sole();
        $this->assertSame('2026-11-02 04:00:00', Carbon::parse($quiz->opens_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 12:30:00', Carbon::parse($quiz->closes_at)->format('Y-m-d H:i:s'));

        // The staff screen must show back exactly what was typed.
        $this->get(route('quizzes.show', $quiz->id))->assertOk()->assertSee('2026-11-02T09:00', false)->assertSee('2026-11-02T17:30', false);
    }

    public function test_a_window_opening_soon_in_local_time_is_not_rejected_as_being_in_the_past(): void
    {
        // The regression this layer exists to prevent: comparing a local wall-clock string with
        // after:now resolved in UTC rejected any window inside the next five hours.
        Carbon::setTestNow(Carbon::parse('2026-11-02 06:00:00', 'UTC')); // 11:00 PKT
        $teacher = $this->staff();
        $course = $this->course($teacher);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Opens in two local hours', 'instructions' => 'Instructions.',
            'opens_at' => '2026-11-02T13:00',   // 08:00 UTC, two hours from now
            'closes_at' => '2026-11-02T14:00',  // 09:00 UTC
            'duration_minutes' => 30,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, DB::table('quizzes')->where('course_id', $course->id)->count());
        Carbon::setTestNow();
    }

    public function test_a_window_genuinely_in_the_past_is_still_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 06:00:00', 'UTC')); // 11:00 PKT
        $teacher = $this->staff();
        $course = $this->course($teacher);

        $this->actingAs($teacher)->post(route('quizzes.store', $course), [
            'title' => 'Already closed', 'instructions' => 'Instructions.',
            'opens_at' => '2026-11-02T08:00',
            'closes_at' => '2026-11-02T09:00',  // 04:00 UTC, two hours ago
            'duration_minutes' => 30,
        ])->assertSessionHasErrors('closes_at');

        Carbon::setTestNow();
    }

    public function test_an_assignment_deadline_round_trips_through_the_display_zone(): void
    {
        $teacher = $this->staff();
        $course = $this->course($teacher);

        $this->actingAs($teacher)->post(route('assignments.store', $course), [
            'title' => 'Timezone assignment', 'instructions' => 'Describe the cycle.',
            'due_at' => '2026-12-01T23:59', 'max_marks' => 10,
        ])->assertRedirect();

        $assignment = Assignment::where('course_id', $course->id)->sole();
        $this->assertSame('2026-12-01 18:59:00', $assignment->due_at->format('Y-m-d H:i:s'));

        $this->get(route('assignments.show', $assignment))->assertOk()
            ->assertSee('Dec 1, 2026 · 23:59 PKT')
            ->assertSee('2026-12-01T23:59', false);
    }

    public function test_editing_an_assignment_keeps_the_deadline_stable_when_it_is_not_changed(): void
    {
        $teacher = $this->staff();
        $course = $this->course($teacher);
        $this->actingAs($teacher)->post(route('assignments.store', $course), [
            'title' => 'Stable', 'instructions' => 'Instructions.', 'due_at' => '2026-12-01T23:59', 'max_marks' => 10,
        ])->assertRedirect();
        $assignment = Assignment::where('course_id', $course->id)->sole();
        $stored = $assignment->due_at->format('Y-m-d H:i:s');

        // Resubmitting the form unchanged must not drift the instant by the offset each time.
        for ($i = 0; $i < 2; $i++) {
            $assignment->refresh();
            $this->patch(route('assignments.update', $assignment), [
                'version' => $assignment->version,
                'title' => 'Stable', 'instructions' => 'Instructions.',
                'due_at' => DisplayTime::forInput($assignment->due_at),
                'max_marks' => 10,
            ])->assertRedirect();
        }

        $this->assertSame($stored, $assignment->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_an_extension_typed_locally_is_compared_against_the_stored_deadline(): void
    {
        $teacher = $this->staff();
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);
        $this->actingAs($teacher)->post(route('assignments.store', $course), [
            'title' => 'Extendable', 'instructions' => 'Instructions.', 'due_at' => '2026-12-01T12:00', 'max_marks' => 10,
        ])->assertRedirect();
        $assignment = Assignment::where('course_id', $course->id)->sole();

        // 13:00 PKT is later than a 12:00 PKT deadline. Compared naively against the stored
        // 07:00 UTC it would look like a whole day's extension; compared wrongly the other way
        // it would be rejected as earlier.
        $this->post(route('assignments.extend', $assignment), [
            'user_id' => $student->id, 'due_at' => '2026-12-01T13:00', 'reason' => 'Approved short extension.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-12-01 08:00:00', Carbon::parse(DB::table('assignment_extensions')->value('due_at'))->format('Y-m-d H:i:s'));
    }

    public function test_an_extension_earlier_than_the_deadline_is_still_refused(): void
    {
        $teacher = $this->staff();
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course($teacher);
        $course->enrollments()->create(['user_id' => $student->id]);
        $this->actingAs($teacher)->post(route('assignments.store', $course), [
            'title' => 'Extendable', 'instructions' => 'Instructions.', 'due_at' => '2026-12-01T12:00', 'max_marks' => 10,
        ])->assertRedirect();
        $assignment = Assignment::where('course_id', $course->id)->sole();

        $this->post(route('assignments.extend', $assignment), [
            'user_id' => $student->id, 'due_at' => '2026-12-01T11:00', 'reason' => 'Earlier than the deadline.',
        ])->assertSessionHasErrors('due_at');
    }
}
