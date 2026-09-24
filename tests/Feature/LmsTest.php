<?php

namespace Tests\Feature;

use App\Jobs\GenerateStudyNotes;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\Submission;
use App\Models\User;
use App\Services\NotesProvider;
use App\Services\SourceText;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LmsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => $role])->save();

        return $u;
    }

    private function course(?User $instructor = null, string $status = 'published'): Course
    {
        return Course::create(['instructor_id' => ($instructor ?? $this->user('instructor'))->id, 'title' => 'Secure learning', 'description' => 'Course description', 'status' => $status]);
    }

    private function enroll(User $u, Course $c): void
    {
        Enrollment::create(['user_id' => $u->id, 'course_id' => $c->id]);
    }

    private function lesson(Course $c, string $body = 'A database transaction groups operations into a single unit of work.'): Lesson
    {
        return $c->lessons()->create(['title' => 'Transactions', 'body' => $body, 'position' => 1]);
    }

    private function assignment(Course $c): Assignment
    {
        return $c->assignments()->create(['title' => 'Practice', 'instructions' => 'Explain transactions', 'due_at' => now()->addHour(), 'max_marks' => 20]);
    }

    private function requestNote(User $u, Lesson $l): StudyNote
    {
        Queue::fake();
        $this->actingAs($u)->post('/notes', ['source_type' => 'lesson', 'source_id' => $l->id])->assertRedirect();

        return StudyNote::latest('id')->firstOrFail();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Escalation', 'email' => 'new@example.test', 'password' => 'a-long-password', 'role' => 'admin'])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_login_logout_and_password_reset_work(): void
    {
        Notification::fake();
        $u = User::factory()->create(['password' => 'a-long-password']);
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => 'a-long-password'])->assertRedirect('/dashboard/learner');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHas('status');
        Notification::assertSentTo($u, ResetPassword::class);
        $token = Password::createToken($u);
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'new-long-password', 'password_confirmation' => 'new-long-password'])->assertRedirect('/login');
        $this->post('/login', ['email' => $u->email, 'password' => 'new-long-password'])->assertRedirect('/dashboard/learner');
    }

    public function test_authentication_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'a@example.test', 'password' => 'wrong']);
        }$this->post('/login', ['email' => 'a@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_course_content_requires_enrollment_and_published_status(): void
    {
        $u = $this->user();
        $c = $this->course();
        $l = $this->lesson($c);
        $this->actingAs($u)->get('/courses/'.$c->id)->assertForbidden();
        $this->post('/courses/'.$c->id.'/enroll')->assertRedirect(route('courses.show', $c));
        $this->post('/courses/'.$c->id.'/enroll')->assertRedirect(route('courses.show', $c));
        $this->assertDatabaseCount('enrollments', 1);
        $this->get('/courses/'.$c->id)->assertOk()->assertSee($l->body);
        $c->update(['status' => 'draft']);
        $this->get('/courses/'.$c->id)->assertForbidden();
        $this->post('/courses/'.$c->id.'/enroll')->assertForbidden();
    }

    public function test_instructors_manage_only_assigned_courses(): void
    {
        $owner = $this->user('instructor');
        $other = $this->user('instructor');
        $c = $this->course($owner);
        $this->actingAs($other)->get('/courses/'.$c->id.'/edit')->assertForbidden();
        $this->post('/courses/'.$c->id.'/lessons', ['title' => 'x', 'body' => 'x', 'position' => 1])->assertForbidden();
        $this->actingAs($owner)->post('/courses/'.$c->id.'/lessons', ['title' => 'Lesson', 'body' => 'Text', 'position' => 1])->assertRedirect();
        $this->actingAs($this->user())->get('/courses/create')->assertForbidden();
    }

    public function test_admin_can_assign_courses_and_update_settings_but_students_cannot(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('instructor');
        $student = $this->user();
        $this->actingAs($student)->get('/admin')->assertForbidden();
        $this->patch('/admin/users/'.$student->id, ['name' => 'Escalation', 'role' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->post('/courses', ['code' => 'NEW-101', 'title' => 'New course', 'description' => 'Test', 'status' => 'draft', 'instructor_id' => $teacher->id])->assertRedirect();
        $this->put('/admin/settings', ['site_name' => 'Our academy'])->assertRedirect();
        $this->get('/admin')->assertOk()->assertSee('Our academy');
        $this->patch('/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'student', 'is_active' => true, 'version' => 0, 'reason' => 'Attempt role change'])->assertStatus(422);
        $this->patch('/admin/users/'.$teacher->id, ['name' => $teacher->name, 'email' => $teacher->email, 'role' => 'student', 'is_active' => true, 'version' => 0, 'reason' => 'Attempt role change'])->assertStatus(422);
    }

    public function test_progress_requires_explicit_completion_and_handles_empty_courses(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $this->assertSame(0, $c->progressFor($u));
        $a = $this->lesson($c);
        $b = $this->lesson($c, 'Second lesson');
        $this->actingAs($u)->get('/courses/'.$c->id)->assertOk();
        $this->assertSame(0, $c->progressFor($u));
        $this->post('/lessons/'.$a->id.'/complete', ['completed' => 1])->assertRedirect();
        $this->post('/lessons/'.$a->id.'/complete', ['completed' => 1])->assertRedirect();
        $this->assertSame(50, $c->progressFor($u));
        $this->post('/lessons/'.$b->id.'/complete', ['completed' => 1]);
        $this->assertSame(100, $c->progressFor($u));
        $this->post('/lessons/'.$a->id.'/complete', ['completed' => 0]);
        $this->assertSame(50, $c->progressFor($u));
    }

    public function test_submission_grading_privacy_and_resubmission_rules(): void
    {
        $teacher = $this->user('instructor');
        $u = $this->user();
        $other = $this->user();
        $c = $this->course($teacher);
        $a = $this->assignment($c);
        $this->enroll($u, $c);
        $this->enroll($other, $c);
        $this->actingAs($u)->post('/assignments/'.$a->id.'/submit', ['body' => 'Private response'])->assertRedirect();
        $s = Submission::firstOrFail();
        $this->assertFalse($s->is_late);
        $this->post('/assignments/'.$a->id.'/submit', ['body' => 'Revised private response'])->assertRedirect();
        $this->assertDatabaseCount('submissions', 1);
        $this->actingAs($other)->get('/assignments/'.$a->id)->assertOk()->assertDontSee('Revised private response')->assertDontSee('@endsection')->assertSee('main', false);
        $this->patch('/submissions/'.$s->id.'/grade', ['grade' => 10])->assertForbidden();
        $this->actingAs($this->user('instructor'))->patch('/submissions/'.$s->id.'/grade', ['grade' => 10])->assertForbidden();
        $this->actingAs($teacher)->patch('/submissions/'.$s->id.'/grade', ['grade' => 21])->assertSessionHasErrors('grade');
        $this->patch('/submissions/'.$s->id.'/grade', ['grade' => 18, 'feedback' => 'Well explained', 'version' => 1, 'reason' => 'Initial grading'])->assertRedirect();
        $this->post(route('submissions.publish', $s), ['version' => 2, 'confirm' => 1, 'reason' => 'Reviewed result'])->assertRedirect();
        $this->actingAs($u)->get('/assignments/'.$a->id)->assertOk()->assertSee('Well explained');
        $this->post('/assignments/'.$a->id.'/submit', ['body' => 'Replace graded'])->assertStatus(409);
    }

    public function test_unauthorized_assignment_and_announcement_access_is_denied(): void
    {
        $c = $this->course();
        $a = $this->assignment($c);
        $u = $this->user();
        $this->actingAs($u)->get('/assignments/'.$a->id)->assertForbidden();
        $this->post('/assignments/'.$a->id.'/submit', ['body' => 'Text'])->assertForbidden();
        $this->post('/courses/'.$c->id.'/announcements', ['title' => 'x', 'body' => 'x'])->assertForbidden();
        $this->actingAs($c->instructor)->post('/courses/'.$c->id.'/announcements', ['title' => 'Welcome', 'body' => 'Private announcement'])->assertRedirect();
        $this->enroll($u, $c);
        $this->actingAs($u)->get('/courses/'.$c->id)->assertSee('Private announcement');
    }

    public function test_upload_validation_and_private_material_downloads(): void
    {
        Storage::fake('local');
        $teacher = $this->user('instructor');
        $c = $this->course($teacher);
        $u = $this->user();
        $this->actingAs($teacher)->post('/courses/'.$c->id.'/materials', ['title' => 'Exploit', 'file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->post('/courses/'.$c->id.'/materials', ['title' => 'Too large', 'file' => UploadedFile::fake()->create('big.txt', 10241, 'text/plain')])->assertSessionHasErrors('file');
        $this->post('/courses/'.$c->id.'/materials', ['title' => 'Reader', 'file' => UploadedFile::fake()->createWithContent('reader.txt', 'Readable text')])->assertRedirect();
        $m = Material::firstOrFail();
        Storage::disk('local')->assertExists($m->path);
        $this->actingAs($u)->get('/materials/'.$m->id.'/download')->assertForbidden();
        $this->enroll($u, $c);
        $this->get('/materials/'.$m->id.'/download')->assertDownload('reader.txt');
        $c->update(['status' => 'archived']);
        $this->get('/materials/'.$m->id.'/download')->assertForbidden();
    }

    public function test_material_cannot_be_attached_to_another_courses_lesson(): void
    {
        Storage::fake('local');
        $c = $this->course();
        $l = $this->lesson($this->course());
        $this->actingAs($c->instructor)->post('/courses/'.$c->id.'/materials', ['title' => 'Wrong link', 'lesson_id' => $l->id, 'file' => UploadedFile::fake()->createWithContent('x.txt', 'text')])->assertSessionHasErrors('lesson_id');
    }

    public function test_submission_downloads_are_private(): void
    {
        Storage::fake('local');
        $c = $this->course();
        $a = $this->assignment($c);
        $u = $this->user();
        $other = $this->user();
        $this->enroll($u, $c);
        $this->enroll($other, $c);
        $this->actingAs($u)->post('/assignments/'.$a->id.'/submit', ['file' => UploadedFile::fake()->createWithContent('answer.txt', 'Private answer')])->assertRedirect();
        $s = Submission::firstOrFail();
        $this->get('/submissions/'.$s->id.'/download')->assertDownload('answer.txt');
        $this->actingAs($other)->get('/submissions/'.$s->id.'/download')->assertForbidden();
        $this->actingAs($c->instructor)->get('/submissions/'.$s->id.'/download')->assertDownload('answer.txt');
    }

    public function test_note_requests_are_authorized_and_deduplicated(): void
    {
        Queue::fake();
        $u = $this->user();
        $c = $this->course();
        $l = $this->lesson($c);
        $data = ['source_type' => 'lesson', 'source_id' => $l->id];
        $this->actingAs($u)->post('/notes', $data)->assertForbidden();
        $this->enroll($u, $c);
        $this->post('/notes', $data)->assertRedirect();
        $this->post('/notes', $data)->assertRedirect();
        $this->assertDatabaseCount('study_notes', 1);
        Queue::assertPushed(GenerateStudyNotes::class, 1);
        $this->assertSame(1, $u->refresh()->ai_usage_count);
    }

    public function test_notes_are_private_including_rename_and_delete(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $n = $this->requestNote($u, $this->lesson($c));
        $this->actingAs($this->user('admin'))->get('/notes/'.$n->id)->assertForbidden();
        $this->patch('/notes/'.$n->id, ['title' => 'Stolen'])->assertForbidden();
        $this->delete('/notes/'.$n->id)->assertForbidden();
        $this->get('/notes')->assertDontSee($n->title);
        $this->actingAs($u)->delete('/notes/'.$n->id)->assertStatus(409);
        $this->patch('/notes/'.$n->id, ['title' => 'My revision'])->assertRedirect();
        $this->get('/notes/'.$n->id)->assertSee('My revision');
        $n->update(['status' => 'completed']);
        $this->delete('/notes/'.$n->id)->assertRedirect('/notes');
    }

    public function test_personal_note_editing_and_regeneration_are_private_confirmed_and_idempotent(): void
    {
        $user = $this->user();
        $course = $this->course();
        $this->enroll($user, $course);
        $note = $this->requestNote($user, $this->lesson($course));
        $note->update(['status' => 'completed', 'content' => 'Original generated notes']);
        $this->patch(route('notes.update', $note), ['title' => 'My notes', 'content' => 'My private edits'])->assertRedirect();
        $this->assertSame('My private edits', $note->fresh()->content);
        $this->assertNotNull($note->fresh()->edited_at);
        $this->actingAs($this->user())->post(route('notes.regenerate', $note), ['confirm' => 1])->assertForbidden();
        $this->actingAs($user)->post(route('notes.regenerate', $note))->assertSessionHasErrors('confirm');
        Queue::fake();
        $this->post(route('notes.regenerate', $note), ['confirm' => 1])->assertRedirect();
        $this->post(route('notes.regenerate', $note), ['confirm' => 1])->assertRedirect();
        Queue::assertPushed(GenerateStudyNotes::class, 1);
        $this->assertSame('pending', $note->fresh()->status);
        $this->assertNull($note->fresh()->content);
        $this->assertSame(2, $user->fresh()->ai_usage_count);
        $this->patch(route('notes.update', $note), ['title' => 'My notes', 'content' => 'Edit while processing'])->assertConflict();
    }

    public function test_mock_generation_and_duplicate_job_delivery_save_once(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $n = $this->requestNote($u, $this->lesson($c));
        $job = new GenerateStudyNotes($n->id);
        $job->handle(app(SourceText::class), new NotesProvider);
        $this->assertSame('completed', $n->refresh()->status);
        $this->assertStringContainsString('DEVELOPMENT MOCK', $n->content);
        $stamp = $n->generated_at;
        $job->handle(app(SourceText::class), new NotesProvider);
        $this->assertDatabaseCount('study_notes', 1);
        $this->assertEquals($stamp, $n->refresh()->generated_at);
    }

    public function test_worker_rechecks_access_and_source_version(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $l = $this->lesson($c);
        $n = $this->requestNote($u, $l);
        $c->update(['status' => 'draft']);
        (new GenerateStudyNotes($n->id))->handle(app(SourceText::class), new NotesProvider);
        $this->assertSame('failed', $n->refresh()->status);
        $c->update(['status' => 'published']);
        $n->update(['status' => 'pending']);
        $l->update(['body' => 'Changed course text']);
        (new GenerateStudyNotes($n->id))->handle(app(SourceText::class), new NotesProvider);
        $this->assertSame('failed', $n->refresh()->status);
        $this->assertStringContainsString('source changed', $n->error);
    }

    public function test_provider_failure_is_sanitized_and_eventually_marks_failed(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $n = $this->requestNote($u, $this->lesson($c));
        $provider = \Mockery::mock(NotesProvider::class);
        $provider->shouldReceive('generate')->andThrow(new \RuntimeException('SECRET provider data'));
        $job = new GenerateStudyNotes($n->id);
        try {
            $job->handle(app(SourceText::class), $provider);
            $this->fail('Expected retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('Study notes generation temporarily failed.', $e->getMessage());
        }
        $this->assertSame('pending', $n->refresh()->status);
        $job->failed(new \RuntimeException('SECRET'));
        $this->assertSame('failed', $n->refresh()->status);
        $this->assertStringNotContainsString('SECRET', $n->error);
    }

    public function test_daily_quota_survives_note_deletion(): void
    {
        config(['study.daily_limit' => 1]);
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $l = $this->lesson($c);
        $n = $this->requestNote($u, $l);
        $n->update(['status' => 'completed']);
        $this->delete('/notes/'.$n->id)->assertRedirect();
        $this->post('/notes', ['source_type' => 'lesson', 'source_id' => $l->id])->assertStatus(429);
    }

    public function test_unsupported_empty_and_oversized_ai_sources_are_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $this->actingAs($u);
        foreach (['', str_repeat('a', 12001)] as $text) {
            $l = $this->lesson($c, $text);
            $this->post('/notes', ['source_type' => 'lesson', 'source_id' => $l->id])->assertSessionHasErrors('source');
        }
        $m = $c->materials()->create(['title' => 'PDF', 'path' => 'file.pdf', 'original_name' => 'file.pdf', 'format' => 'pdf']);
        $this->post('/notes', ['source_type' => 'material', 'source_id' => $m->id])->assertSessionHasErrors('source');
        Queue::assertNothingPushed();
    }

    public function test_real_provider_contract_sends_only_source_and_handles_rate_limit_empty_and_timeout(): void
    {
        config(['study.api_key' => 'fake-test-key']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Summary: transactions are atomic.']]]]])]);
        $this->assertStringContainsString('transactions', (new NotesProvider)->generate('Transactions are atomic.', 'openai'));
        Http::assertSent(fn ($r) => $r['store'] === false && $r['max_output_tokens'] === 1200 && json_decode($r['input'], true) === ['source_text' => 'Transactions are atomic.'] && ! isset($r['tools']));
        foreach ([Http::response([], 429), Http::response(['status' => 'completed', 'output' => []]), Http::failedConnection()] as $failure) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.openai.com/*' => $failure]);
            $thrown = false;
            try {
                (new NotesProvider)->generate('text', 'openai');
            } catch (\Throwable $e) {
                $thrown = true;
            }$this->assertTrue($thrown);
        }
    }

    public function test_database_queue_processes_a_real_request(): void
    {
        config(['queue.default' => 'database']);
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $l = $this->lesson($c);
        $this->actingAs($u)->post('/notes', ['source_type' => 'lesson', 'source_id' => $l->id])->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--tries' => 3])->assertExitCode(0);
        $this->assertDatabaseHas('study_notes', ['status' => 'completed']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_html_is_escaped_in_lessons_and_ai_notes(): void
    {
        $u = $this->user();
        $c = $this->course();
        $this->enroll($u, $c);
        $l = $this->lesson($c, '<script>alert(1)</script>');
        $this->actingAs($u)->get('/courses/'.$c->id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $n = $this->requestNote($u, $l);
        $n->update(['status' => 'completed', 'content' => '<script>alert(2)</script>']);
        $this->get('/notes/'.$n->id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(2)</script>', false);
    }

    public function test_all_role_screens_render(): void
    {
        foreach (['student', 'instructor', 'admin'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u)->get('/dashboard')->assertOk();
            $this->get('/courses')->assertOk();
            $this->get('/notes')->assertOk();
        }
    }

    public function test_academic_foreign_keys_prevent_course_deletion(): void
    {
        $c = $this->course();
        $this->assignment($c);
        $this->expectException(QueryException::class);
        $c->delete();
    }
}
