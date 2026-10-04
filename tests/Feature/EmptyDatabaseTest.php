<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MinimalLoginSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An installation holding nothing but login accounts is a valid state, not a broken one.
 *
 * Every screen reachable without content must render its empty state rather than failing on a
 * relationship that happens to be absent.
 */
class EmptyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        $this->seed(MinimalLoginSeeder::class);

        return User::where('role', $role)->sole();
    }

    /** @return list<array{0: string, 1: string}> */
    public static function sharedScreens(): array
    {
        return [
            'dashboard' => ['dashboard', 'student'],
            'course catalog' => ['courses.index', 'student'],
            'study notes' => ['notes.index', 'student'],
            'announcements' => ['announcements.index', 'student'],
            'profile' => ['profile.show', 'student'],
            'instructor dashboard' => ['dashboard', 'instructor'],
            'instructor catalog' => ['courses.index', 'instructor'],
            'instructor announcements' => ['announcements.index', 'instructor'],
            'admin dashboard' => ['dashboard', 'admin'],
            'admin catalog' => ['courses.index', 'admin'],
            'administration' => ['admin', 'admin'],
            'ai administration' => ['admin.ai', 'admin'],
            'course creation form' => ['courses.create', 'admin'],
        ];
    }

    #[DataProvider('sharedScreens')]
    public function test_a_screen_renders_with_no_application_content(string $route, string $role): void
    {
        $this->actingAs($this->account($role))->get(route($route))->assertOk();
    }

    public function test_every_role_can_sign_in_against_a_freshly_seeded_installation(): void
    {
        $this->seed(MinimalLoginSeeder::class);

        foreach (['admin', 'instructor', 'student'] as $role) {
            $this->post('/login', ['email' => $role.'@acumen.test', 'password' => MinimalLoginSeeder::PASSWORD])
                ->assertRedirect(route('dashboard.'.$role));
            $this->assertAuthenticated();
            $this->post(route('logout'))->assertRedirect('/login');
        }
    }

    public function test_the_minimal_seed_creates_accounts_and_no_content(): void
    {
        $this->seed(MinimalLoginSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertSame(['admin', 'instructor', 'student'], User::orderBy('role')->pluck('role')->all());
        foreach (['courses', 'lessons', 'assignments', 'quizzes', 'enrollments', 'submissions', 'announcements', 'materials', 'study_notes', 'video_lectures'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must stay empty after the minimal seed.');
        }
    }

    public function test_the_minimal_seed_is_idempotent_and_never_stores_a_plaintext_password(): void
    {
        $this->seed(MinimalLoginSeeder::class);
        $this->seed(MinimalLoginSeeder::class);

        $this->assertSame(3, User::count());
        foreach (User::all() as $user) {
            $this->assertNotSame(MinimalLoginSeeder::PASSWORD, $user->password);
            $this->assertTrue(password_get_info($user->password)['algo'] !== null, 'Passwords must be stored hashed.');
        }
    }

    public function test_an_empty_installation_still_serves_the_sign_in_page(): void
    {
        DB::table('users')->delete();

        $this->get('/login')->assertOk();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_learner_sees_an_empty_catalog_rather_than_an_error(): void
    {
        $this->actingAs($this->account('student'))->get(route('courses.index'))
            ->assertOk()
            ->assertSee('EduLearn', false);
    }

    public function test_searching_an_empty_catalog_is_safe(): void
    {
        $this->actingAs($this->account('instructor'))->get(route('courses.index', ['q' => 'anything at all']))->assertOk();
    }
}
