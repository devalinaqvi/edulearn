<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\User;
use App\Services\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publication is decided by the server at read time, not by a timer in a browser and not by a
 * background job: an announcement becomes visible because its publication time has passed, so a
 * stopped worker can never hold one back or release one early.
 */
class ScheduledAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_an_announcement_can_be_published_immediately(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin)->post(route('announcements.platform'), ['title' => 'Live now', 'body' => 'Visible', 'state' => 'now'])->assertRedirect();

        $this->assertSame('published', Announcement::sole()->state());
        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertSee('Live now');
    }

    public function test_a_scheduled_announcement_is_withheld_until_its_time_then_appears_by_itself(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin)->post(route('announcements.platform'), [
            'title' => 'Enrolment opens', 'body' => 'Later', 'state' => 'schedule',
            'publish_at' => DisplayTime::forInput(now()->addDays(3)),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $announcement = Announcement::sole();
        $this->assertSame('scheduled', $announcement->state());

        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertDontSee('Enrolment opens');

        // No worker runs here: visibility is a query condition, so time passing is enough.
        $this->travelTo(now()->addDays(4));
        $this->get(route('announcements.index'))->assertOk()->assertSee('Enrolment opens');
    }

    public function test_a_draft_is_never_released_on_its_own(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin)->post(route('announcements.platform'), ['title' => 'Unfinished', 'body' => 'Draft', 'state' => 'draft'])->assertRedirect();
        $this->assertSame('draft', Announcement::sole()->state());

        $this->travelTo(now()->addYear());
        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertDontSee('Unfinished');
    }

    public function test_a_past_publication_time_is_refused_rather_than_silently_backdated(): void
    {
        $this->actingAs($this->admin())->post(route('announcements.platform'), [
            'title' => 'Backdated', 'body' => 'x', 'state' => 'schedule',
            'publish_at' => DisplayTime::forInput(now()->subDay()),
        ])->assertSessionHasErrors('publish_at');

        $this->assertSame(0, Announcement::count());
    }

    public function test_scheduling_without_a_time_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('announcements.platform'), ['title' => 'When?', 'body' => 'x', 'state' => 'schedule'])
            ->assertSessionHasErrors('publish_at');
    }

    public function test_an_unpublished_announcement_can_be_edited_and_released_early(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($admin)->post(route('announcements.platform'), [
            'title' => 'Original', 'body' => 'Original body', 'state' => 'schedule',
            'publish_at' => DisplayTime::forInput(now()->addWeek()),
        ])->assertRedirect();
        $announcement = Announcement::sole();

        $this->patch(route('announcements.update', $announcement), ['title' => 'Corrected', 'body' => 'Corrected body', 'state' => 'now'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('published', $announcement->fresh()->state());
        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertSee('Corrected');
    }

    public function test_a_published_announcement_can_no_longer_be_edited_or_discarded(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('announcements.platform'), ['title' => 'Already out', 'body' => 'Read by people', 'state' => 'now'])->assertRedirect();
        $announcement = Announcement::sole();

        $this->patch(route('announcements.update', $announcement), ['title' => 'Rewritten', 'body' => 'Rewritten', 'state' => 'now'])->assertSessionHasErrors('conflict');
        $this->delete(route('announcements.discard', $announcement))->assertSessionHasErrors('conflict');

        $this->assertSame('Already out', $announcement->fresh()->title);
    }

    public function test_an_unpublished_announcement_can_be_discarded(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('announcements.platform'), ['title' => 'Mistake', 'body' => 'x', 'state' => 'draft'])->assertRedirect();

        $this->delete(route('announcements.discard', Announcement::sole()))->assertRedirect(route('announcements.index'));

        $this->assertSame(0, Announcement::count());
    }

    public function test_pending_announcements_are_listed_only_for_their_author(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create(['role' => 'instructor']);
        $other = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-ANN', 'instructor_id' => $teacher->id, 'title' => 'Course', 'description' => 'Online', 'status' => 'published']);

        $this->actingAs($teacher)->post(route('announcements.store', $course), [
            'title' => 'Course draft', 'body' => 'x', 'state' => 'draft',
        ])->assertRedirect();

        $this->get(route('announcements.index'))->assertOk()->assertSee('Course draft')->assertSee('Draft');
        $this->actingAs($other)->get(route('announcements.index'))->assertOk()->assertDontSee('Course draft');
        $this->actingAs($admin)->get(route('announcements.index'))->assertOk()->assertDontSee('Course draft');
    }

    public function test_a_scheduled_time_is_read_in_the_display_timezone(): void
    {
        $this->actingAs($this->admin())->post(route('announcements.platform'), [
            'title' => 'Timed', 'body' => 'x', 'state' => 'schedule', 'publish_at' => '2027-03-04T09:00',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // 09:00 PKT is 04:00 UTC.
        $this->assertSame('2027-03-04 04:00:00', Announcement::sole()->published_at->format('Y-m-d H:i:s'));
    }
}
