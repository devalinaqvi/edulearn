<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transient feedback has one presentation and one place in the markup.
 *
 * The messages are rendered server-side rather than injected by script, so they are present
 * before any JavaScript runs and survive its absence; the script only adds dismissal and timing.
 */
class ToastNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_success_message_is_rendered_as_a_polite_toast(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('courses.store'), ['code' => 'EL-TOAST', 'instructor_id' => $teacher->id, 'title' => 'Toast course', 'description' => 'Online', 'status' => 'draft'])
            ->assertOk()
            ->assertSee('toast toast-success', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Course created. Add your first lesson below.');
    }

    public function test_a_validation_problem_is_rendered_as_an_assertive_toast(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // back() needs a previous page for the error bag to be rendered on one.
        $this->actingAs($admin)->get(route('courses.create'))->assertOk();

        $this->followingRedirects()
            ->post(route('courses.store'), ['code' => '', 'title' => '', 'description' => '', 'status' => 'draft'])
            ->assertOk()
            ->assertSee('toast toast-error', false)
            ->assertSee('aria-live="assertive"', false);
    }

    public function test_every_message_offers_a_dismiss_control(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('courses.store'), ['code' => 'EL-DISMISS', 'instructions' => '', 'instructor_id' => $teacher->id, 'title' => 'Course', 'description' => 'Online', 'status' => 'draft'])
            ->assertOk()
            ->assertSee('data-toast-dismiss', false)
            ->assertSee('aria-label="Dismiss this message"', false);
    }

    public function test_the_region_is_present_even_with_nothing_to_report(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        // The live regions must exist on first paint, or a message added later is not announced.
        $this->actingAs($student)->get(route('dashboard'))->assertOk()
            ->assertSee('data-toasts', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('aria-live="assertive"', false)
            ->assertDontSee('toast toast-success', false);
    }

    public function test_inline_guidance_is_not_turned_into_a_toast(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['code' => 'EL-INLINE', 'instructor_id' => $teacher->id, 'title' => 'Course', 'description' => 'Online', 'status' => 'published']);
        $course->enrollments()->create(['user_id' => $student->id]);

        // Standing guidance belongs in the page, not in a message that disappears.
        $this->actingAs($student)->get(route('notes.index'))->assertOk()
            ->assertSee('class="notice"', false)
            ->assertDontSee('toast toast-success', false);
    }
}
