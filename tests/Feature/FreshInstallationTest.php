<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Material;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FreshInstallationTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['admin'], ['instructor'], ['student']];
    }

    #[DataProvider('roles')]
    public function test_seeded_accounts_can_log_in_and_open_their_authorized_screens(string $role): void
    {
        Storage::fake('local');
        $this->seed();
        $this->post('/login', ['email' => $role.'@acumen.test', 'password' => 'Learning-demo-2026!'])
            ->assertRedirect(route('dashboard.'.$role));
        foreach (['dashboard.'.$role, 'courses.index', 'profile.show', 'notes.index', 'announcements.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (Course::all() as $course) {
            foreach (['courses.show', 'courses.overview', 'lectures.index', 'quizzes.index'] as $route) {
                $this->get(route($route, $course))->assertOk();
            }
            foreach ($course->assignments as $assignment) {
                $this->get(route('assignments.show', $assignment))->assertOk();
            }
        }
        foreach (Material::all() as $material) {
            $this->get(route('materials.download', $material))->assertOk();
            $this->assertNotNull($material->uploader_id);
            $this->assertGreaterThan(0, $material->size_bytes);
            $this->assertNotNull($material->uploaded_at);
        }
        foreach (['admin', 'admin.ai', 'courses.create'] as $route) {
            $this->get(route($route))->assertStatus($role === 'admin' ? 200 : 403);
        }
        $this->get(route('quizzes.show', DB::table('quizzes')->value('id')))->assertOk();
        $this->post(route('logout'))->assertRedirect('/login');
        $this->get(route('dashboard'))->assertRedirect('/login');
    }

    public function test_seeded_learner_can_submit_work_and_read_only_published_feedback(): void
    {
        Storage::fake('local');
        $this->seed();
        $learner = User::where('role', 'student')->firstOrFail();
        $teacher = User::where('role', 'instructor')->firstOrFail();
        $assignment = Course::firstOrFail()->assignments()->whereNull('rubric')->firstOrFail();
        $this->actingAs($learner)->post(route('assignments.submit', $assignment), ['body' => 'A clear explanation with a practical example.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $submission = Submission::where('assignment_id', $assignment->id)->where('user_id', $learner->id)->firstOrFail();
        $this->flushSession();
        $this->actingAs($teacher)->patch(route('submissions.grade', $submission), ['version' => 0, 'grade' => 80, 'feedback' => 'Well explained seeded workflow', 'reason' => 'Reviewed'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('graded', $submission->fresh()->status);
        $this->flushSession();
        $this->actingAs($learner)->get(route('assignments.show', $assignment))->assertDontSee('Well explained seeded workflow');
        $this->flushSession();
        $this->actingAs($teacher)->post(route('submissions.publish', $submission), ['version' => 1, 'confirm' => 1, 'reason' => 'Approved'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($learner)->get(route('assignments.show', $assignment))->assertSee('Well explained seeded workflow');
    }

    public function test_preparation_preserves_an_existing_key(): void
    {
        $key = config('app.key');
        $this->artisan('lms:prepare')->expectsOutput('Existing APP_KEY preserved.')->assertSuccessful();
        $this->assertSame($key, config('app.key'));
    }

    public function test_new_installation_generates_a_key_only_once(): void
    {
        $directory = storage_path('framework/testing/install-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/.env', "APP_KEY=\n");
        $this->app->useEnvironmentPath($directory);
        config(['app.key' => null]);
        try {
            $this->artisan('lms:prepare')->assertSuccessful();
            $first = File::get($directory.'/.env');
            $this->assertStringContainsString('APP_KEY=base64:', $first);
            $this->artisan('lms:prepare')->assertSuccessful();
            $this->assertSame($first, File::get($directory.'/.env'));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_preparation_refuses_key_generation_for_an_existing_database(): void
    {
        User::factory()->create();
        config(['app.key' => null]);
        $this->artisan('lms:prepare')
            ->expectsOutput('This database already contains users. Restore its original APP_KEY; do not generate a replacement during setup.')
            ->assertFailed();
        $this->assertNull(config('app.key'));
    }

    public function test_unsafe_test_database_is_refused_before_any_connection_or_schema_reset(): void
    {
        $code = 'require "vendor/autoload.php"; (new Tests\\Feature\\FreshInstallationTest("test_preparation_preserves_an_existing_key"))->createApplication();';
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'guard_probe', 'DB_URL' => '', 'DB_HOST' => 'invalid.invalid']);
        $process->setTimeout(15)->run();
        $this->assertFalse($process->isSuccessful());
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('Refusing to run tests against an unsafe database [guard_probe]', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }
}
