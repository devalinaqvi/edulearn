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
}
