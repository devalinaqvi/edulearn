<?php

namespace Tests\Feature;

use App\Actions\WriteLock;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WriteLockGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_coordination_row_stops_mutations_instead_of_running_them_unserialized(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-LOCK', 'instructor_id' => $teacher->id, 'title' => 'Locking', 'description' => 'Online', 'status' => 'published']);

        DB::table('lms_write_locks')->delete();

        // Before this guard existed the query simply returned null and the transaction continued
        // without any lock, so the mutation succeeded while silently losing its serialization.
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        $this->actingAs($admin)->put(route('courses.update', $course), [
            'code' => 'EL-LOCK',
            'instructor_id' => $teacher->id,
            'title' => 'Renamed under a missing lock',
            'description' => 'Online',
            'status' => 'published',
        ]);
    }

    public function test_the_mutation_is_rolled_back_when_the_lock_cannot_be_acquired(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'instructor']);
        $course = Course::create(['code' => 'EL-ROLL', 'instructor_id' => $teacher->id, 'title' => 'Original title', 'description' => 'Online', 'status' => 'published']);

        DB::table('lms_write_locks')->delete();

        $this->actingAs($admin)->put(route('courses.update', $course), [
            'code' => 'EL-ROLL',
            'instructor_id' => $teacher->id,
            'title' => 'Should not persist',
            'description' => 'Online',
            'status' => 'published',
        ])->assertServerError();

        $this->assertSame('Original title', $course->fresh()->title);
    }

    public function test_a_prepared_installation_acquires_the_lock_inside_a_transaction(): void
    {
        DB::transaction(function () {
            WriteLock::acquire();
            $this->assertSame(1, DB::table('lms_write_locks')->count());
        });
    }
}
