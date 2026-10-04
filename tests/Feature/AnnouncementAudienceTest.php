<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_and_course_audiences_and_unread_state_are_enforced(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $other = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Course', 'description' => 'Online', 'status' => 'published']);
        $this->actingAs($teacher)->post(route('announcements.platform'), ['title' => 'Forged platform', 'body' => 'Not permitted'])->assertForbidden();
        $this->actingAs($admin)->post(route('announcements.platform'), ['title' => 'Global update', 'body' => 'For everyone'])->assertRedirect();
        $this->actingAs($other)->post(route('announcements.store', $course), ['title' => 'Forged course', 'body' => 'Not permitted'])->assertForbidden();
        $this->actingAs($teacher)->post(route('announcements.store', $course), ['title' => 'Course update', 'body' => 'Private course message'])->assertRedirect();
        $announcement = Announcement::where('course_id', $course->id)->firstOrFail();
        $this->assertSame($teacher->id, $announcement->author_id);
        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertSee('Global update')->assertDontSee('Private course message');
        $this->get(route('dashboard'))->assertOk()->assertSee('Global update')->assertDontSee('Private course message');
        $this->post(route('announcements.read', $announcement))->assertForbidden();
        $this->post(route('courses.enroll', $course))->assertRedirect();
        $this->get(route('announcements.index'))->assertOk()->assertSee('Private course message')->assertSee('Unread');
        $this->post(route('announcements.read', $announcement))->assertRedirect();
        $this->post(route('announcements.read', $announcement))->assertRedirect();
        $this->assertDatabaseCount('announcement_reads', 1);
        $this->actingAs($other)->get(route('announcements.index'))->assertOk()->assertDontSee('Private course message');
        $course->update(['status' => 'archived']);
        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertDontSee('Private course message');
        $this->post(route('announcements.read', $announcement))->assertForbidden();
    }

    public function test_a_future_publication_time_hides_an_announcement_until_it_arrives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        // The audience scope used to test only for the presence of published_at, so a scheduled
        // announcement became visible the moment it was stored.
        $scheduled = Announcement::create(['author_id' => $admin->id, 'title' => 'Scheduled notice', 'body' => 'Not due yet', 'published_at' => now()->addDay()]);
        Announcement::create(['author_id' => $admin->id, 'title' => 'Live notice', 'body' => 'Already due', 'published_at' => now()->subMinute()]);

        $this->actingAs($student)->get(route('announcements.index'))->assertOk()
            ->assertSee('Live notice')->assertDontSee('Scheduled notice');

        $this->assertFalse(Announcement::visibleTo($student)->whereKey($scheduled->id)->exists());

        $this->travelTo(now()->addDays(2));

        $this->get(route('announcements.index'))->assertOk()->assertSee('Scheduled notice');
        $this->assertTrue(Announcement::visibleTo($student)->whereKey($scheduled->id)->exists());
    }

    public function test_an_unpublished_draft_is_never_an_audience_member(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $draft = Announcement::create(['author_id' => $admin->id, 'title' => 'Draft notice', 'body' => 'Unpublished']);
        $draft->forceFill(['published_at' => null])->save();

        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertDontSee('Draft notice');
        $this->assertFalse(Announcement::visibleTo($student)->whereKey($draft->id)->exists());
    }
}
