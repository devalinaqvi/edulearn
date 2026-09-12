<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_codes_are_normalized_unique_editable_and_searchable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $payload = ['code' => ' web-101 ', 'title' => 'Web fundamentals', 'description' => 'Learn online', 'status' => 'published', 'instructor_id' => $teacher->id];
        $this->actingAs($admin)->post(route('courses.store'), $payload)->assertRedirect();
        $course = Course::firstOrFail();
        $this->assertSame('WEB-101', $course->code);
        $this->post(route('courses.store'), $payload)->assertSessionHasErrors('code');
        $this->put(route('courses.update', $course), $payload)->assertRedirect();
        $this->put(route('courses.update', $course), array_replace($payload, ['code' => 'WEB-102']))->assertRedirect();
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get('/courses?q=WEB-102')->assertOk()->assertSee('Web fundamentals');
        $this->get('/courses?q=WEB-101')->assertOk()->assertDontSee('Web fundamentals');
        $this->assertDatabaseCount('courses', 1);
    }

    public function test_course_validation_rejects_invalid_codes_and_inactive_instructors(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor', 'is_active' => false]);
        $payload = ['code' => 'Invalid code!', 'title' => 'A course', 'description' => 'Learn', 'status' => 'published', 'instructor_id' => $teacher->id];
        $this->actingAs($admin)->post(route('courses.store'), $payload)->assertSessionHasErrors(['code', 'instructor_id']);
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_archival_preserves_course_evidence_and_blocks_learner_access(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $other = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'Keep course', 'description' => 'Keep content', 'status' => 'published']);
        $assignment = $course->assignments()->create(['title' => 'Keep assignment', 'instructions' => 'Original', 'due_at' => now()->addDay(), 'max_marks' => 20]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'Evidence', 'submitted_at' => now(), 'is_late' => false, 'status' => 'submitted']);
        $payload = ['code' => $course->code, 'title' => $course->title, 'description' => $course->description, 'status' => 'archived'];
        $this->actingAs($student)->post(route('courses.enroll', $course))->assertRedirect();
        $this->actingAs($other)->put(route('courses.update', $course), $payload)->assertForbidden();
        $this->actingAs($teacher)->put(route('courses.update', $course), $payload)->assertRedirect();
        $this->actingAs($student)->get(route('courses.show', $course))->assertForbidden();
        $this->post(route('courses.enroll', $course))->assertForbidden();
        $this->assertDatabaseHas('enrollments', ['course_id' => $course->id, 'user_id' => $student->id]);
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'body' => 'Evidence']);
    }

}
