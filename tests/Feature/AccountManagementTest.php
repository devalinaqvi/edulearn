<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function accountData(User $user): array
    {
        $user->refresh();

        return ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => $user->is_active, 'version' => $user->account_version, 'reason' => 'Authorized account administration'];
    }

    public function test_five_failures_lock_account_across_addresses_and_expire_after_configured_duration(): void
    {
        config(['lms.login_lockout_minutes' => 2]);
        $user = User::factory()->create(['role' => 'student']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->travel(121)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard.student'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('account_activity', ['user_id' => $user->id, 'event' => 'signed_in']);
    }

    public function test_successful_logins_clear_failures_and_redirect_by_role(): void
    {
        foreach (['admin', 'instructor', 'student'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard.'.$role));
            $this->get(route('dashboard.'.$role))->assertOk();
            $this->post('/logout')->assertRedirect('/login');
        }
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('dashboard.admin'))->assertForbidden();
    }

    public function test_admin_provisions_accounts_and_records_no_password_in_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $payload = ['name' => 'New instructor', 'email' => 'newteacher@example.test', 'role' => 'instructor', 'password' => 'secret-password-123', 'password_confirmation' => 'secret-password-123', 'reason' => 'New teaching account'];
        $this->actingAs($student)->post(route('admin.users.store'), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('admin.users.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $created = User::where('email', $payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check($payload['password'], $created->password));
        $this->assertStringStartsWith('$2y$', $created->password);
        $this->assertTrue($created->is_active);
        $this->post(route('admin.users.store'), $payload)->assertSessionHasErrors('email');
        $this->assertStringNotContainsString($payload['password'], DB::table('account_activity')->value('details'));
        $this->get(route('admin'))->assertOk()->assertSee('New instructor')->assertSee('Account created');
    }

    public function test_deactivation_revokes_sessions_and_reactivation_does_not_restore_old_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($admin)->post('/dashboard')->assertMethodNotAllowed();
        $payload = $this->accountData($student);
        $this->patch(route('admin.users', $student), array_replace($payload, ['is_active' => false]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($student->fresh()->is_active);
        $this->actingAs($student)->withSession(['auth_version' => 0])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->actingAs($admin)->patch(route('admin.users', $student), array_replace($this->accountData($student), ['is_active' => true]))->assertRedirect();
        $this->actingAs($student)->withSession(['auth_version' => 0])->get('/dashboard')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect(route('dashboard.student'));
    }

    public function test_admin_cannot_deactivate_self_or_overwrite_stale_account_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($admin)->patch(route('admin.users', $admin), array_replace($this->accountData($admin), ['is_active' => false]))->assertUnprocessable();
        $payload = $this->accountData($student);
        $this->patch(route('admin.users', $student), array_replace($payload, ['name' => 'Updated learner']))->assertRedirect();
        $this->patch(route('admin.users', $student), $payload)->assertConflict();
        $this->assertSame('Updated learner', $student->fresh()->name);
    }

    public function test_profile_requires_current_password_and_never_changes_roles(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('profile.show'))->assertOk();
        $payload = ['name' => 'Updated name', 'email' => $student->email, 'current_password' => 'password'];
        $this->patch(route('profile.update'), $payload + ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->patch(route('profile.update'), array_replace($payload, ['current_password' => 'wrong']))->assertSessionHasErrors('current_password');
        $this->patch(route('profile.update'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated name', $student->fresh()->name);
        $this->assertSame('student', $student->fresh()->role);
        $this->put(route('profile.password'), ['current_password' => 'password', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk();
        $this->assertTrue(Hash::check('new-password-456', $student->fresh()->password));
        $this->withSession(['auth_version' => 0])->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboards_show_only_enrolled_or_assigned_course_activity(): void
    {
        $teacher = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['instructor_id' => $teacher->id, 'title' => 'My online course', 'description' => 'Test course', 'status' => 'published']);
        $assignment = $course->assignments()->create(['title' => 'Upcoming project', 'instructions' => 'Work', 'due_at' => now()->addDay(), 'max_marks' => 10]);
        $course->announcements()->create(['title' => 'Private course notice', 'body' => 'Relevant notice']);
        $this->actingAs($student)->get('/dashboard')->assertOk()->assertDontSee('Upcoming project')->assertDontSee('Private course notice');
        Enrollment::create(['user_id' => $student->id, 'course_id' => $course->id]);
        $this->get('/dashboard')->assertOk()->assertSee($assignment->title)->assertSee('Private course notice');
        $this->actingAs($teacher)->get('/dashboard')->assertOk()->assertSee('Upcoming project');
    }
}
