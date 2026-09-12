<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnlineLmsTest extends TestCase
{
    use RefreshDatabase;

    private function course(User $teacher): Course
    {
        return Course::create(['instructor_id' => $teacher->id, 'title' => 'Online learning', 'description' => 'Course description', 'status' => 'published']);
    }

    public function test_fresh_product_has_no_campus_routes_tables_or_navigation(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $this->actingAs($user)->withSession(['university_context' => 999])->get('/dashboard')->assertOk()->assertDontSee('University registration')->assertDontSee('Timetable')->assertSee('Course catalog');
        foreach (['/university', '/university/administration', '/university/timetable', '/university/sections/1/sessions'] as $path) {
            $this->get($path)->assertNotFound();
        }
        foreach (['universities', 'campuses', 'program_enrollments', 'role_assignments', 'class_sessions', 'rooms', 'registrations'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_course_discovery_shows_outline_without_private_content_and_enrollment_unlocks_learning(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course($teacher);
        $lesson = $course->lessons()->create(['title' => 'Lesson outline', 'body' => 'Private lesson body', 'position' => 1]);
        $this->actingAs($student)->get(route('courses.overview', $course))->assertOk()->assertSee('Lesson outline')->assertDontSee('Private lesson body')->assertSee('Enroll and start learning');
        $this->get(route('courses.show', $course))->assertForbidden();
        $this->post(route('courses.enroll', $course))->assertRedirect(route('courses.show', $course));
        $this->post(route('courses.enroll', $course))->assertRedirect(route('courses.show', $course));
        $this->assertDatabaseCount('course_access_changes', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->get(route('courses.show', $course))->assertOk()->assertSee('Private lesson body')->assertDontSee('context_id');
        $this->post(route('lessons.complete', $lesson), ['completed' => 1])->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertSee('100%');
        $course->update(['status' => 'draft']);
        $this->get(route('courses.overview', $course))->assertNotFound();
        $this->get('/courses?q[]=invalid')->assertSessionHasErrors('q');
    }

    public function test_admin_can_change_course_access_without_deleting_learning_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course($teacher);
        $assignment = $course->assignments()->create(['title' => 'Practice', 'instructions' => 'Explain', 'due_at' => now()->addDay(), 'max_marks' => 20]);
        $payload = ['email' => $student->email, 'action' => 'enroll', 'reason' => 'Support requested enrollment'];
        $this->actingAs($teacher)->post(route('admin.access.change', $course), $payload)->assertForbidden();
        $this->actingAs($admin)->get(route('admin.access', $course))->assertOk();
        $this->post(route('admin.access.change', $course), $payload)->assertRedirect();
        $this->post(route('admin.access.change', $course), $payload)->assertRedirect();
        $this->assertDatabaseCount('course_access_changes', 1);
        $this->actingAs($student)->post(route('assignments.submit', $assignment), ['body' => 'Retain this answer'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.access.change', $course), array_replace($payload, ['action' => 'remove']))->assertRedirect();
        $this->actingAs($student)->get(route('courses.show', $course))->assertForbidden();
        $this->post(route('courses.enroll', $course))->assertForbidden();
        $this->get(route('courses.overview', $course))->assertOk()->assertDontSee('Enroll and start learning');
        $this->assertDatabaseHas('submissions', ['body' => 'Retain this answer']);
        $this->assertDatabaseCount('course_access_changes', 2);
        $this->actingAs($admin)->post(route('admin.access.change', $course), array_replace($payload, ['action' => 'teach']))->assertUnprocessable();
    }

    public function test_self_enrollment_rejects_staff_unpublished_courses_and_forged_learner_ids(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $course = $this->course($teacher);
        $this->actingAs($teacher)->post(route('courses.enroll', $course))->assertForbidden();
        foreach (['draft', 'archived'] as $status) {
            $course->update(['status' => $status]);
            $this->actingAs($student)->post(route('courses.enroll', $course))->assertForbidden();
        }
        $this->assertDatabaseCount('enrollments', 0);
        $course->update(['status' => 'published']);
        $this->post(route('courses.enroll', $course), ['user_id' => $other->id])->assertRedirect();
        $this->assertDatabaseHas('enrollments', ['course_id' => $course->id, 'user_id' => $student->id]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $other->id]);
    }

    public function test_additional_instructors_are_scoped_and_removable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $other = User::factory()->create(['role' => 'instructor']);
        $course = $this->course($teacher);
        $unassigned = $this->course($teacher);
        $payload = ['email' => $other->email, 'action' => 'teach', 'reason' => 'Teaching support'];
        $this->actingAs($admin)->post(route('admin.access.change', $course), $payload)->assertRedirect();
        $this->actingAs($other)->get(route('courses.edit', $course))->assertOk();
        $this->get(route('courses.edit', $unassigned))->assertForbidden();
        $this->get('/courses')->assertOk()->assertSee(route('courses.show', $course))->assertDontSee(route('courses.show', $unassigned));
        $this->actingAs($admin)->patch(route('admin.users', $other), ['name' => $other->name, 'email' => $other->email, 'role' => 'student', 'is_active' => true, 'version' => 0, 'reason' => 'Attempt role change'])->assertUnprocessable();
        $this->post(route('admin.access.change', $course), array_replace($payload, ['action' => 'unteach']))->assertRedirect();
        $this->actingAs($other)->get(route('courses.edit', $course))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.access.change', $course), array_replace($payload, ['email' => $teacher->email, 'action' => 'unteach']))->assertUnprocessable();
    }

    public function test_online_seeder_is_repeatable_and_creates_no_erp_data(): void
    {
        Storage::fake('local');
        $this->seed();
        $this->seed();
        $this->assertDatabaseCount('courses', 3);
        $this->assertDatabaseCount('quizzes', 1);
        $this->assertDatabaseCount('assignments', 4);
        $this->assertDatabaseCount('users', 3);
        $this->assertFalse(Schema::hasTable('universities'));
        $this->assertSame(['admin', 'instructor', 'student'], DB::table('users')->orderBy('role')->pluck('role')->all());
    }
}
