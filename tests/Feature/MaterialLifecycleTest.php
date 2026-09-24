<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\StudyNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $learner;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->learner = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['instructor_id' => $this->teacher->id, 'title' => 'Materials', 'description' => 'Online', 'status' => 'published']);
        Enrollment::create(['course_id' => $this->course->id, 'user_id' => $this->learner->id]);
    }

    private function upload(string $body = 'first revision text'): Material
    {
        $this->actingAs($this->teacher)->post(route('materials.store', $this->course), [
            'title' => 'Week one reader',
            'file' => UploadedFile::fake()->createWithContent('reader.txt', $body),
        ])->assertRedirect();

        return Material::latest('id')->firstOrFail();
    }

    public function test_replacement_retains_the_previous_file_and_metadata_as_a_staff_only_revision(): void
    {
        $material = $this->upload();
        $originalPath = $material->path;
        $this->assertSame(1, $material->version);

        $this->actingAs($this->teacher)->post(route('materials.replace', $material), [
            'version' => 1,
            'title' => 'Week one reader (corrected)',
            'reason' => 'Fixed a factual error on page two.',
            'file' => UploadedFile::fake()->createWithContent('reader-v2.txt', 'second revision text'),
        ])->assertRedirect();

        $material->refresh();
        $this->assertSame(2, $material->version);
        $this->assertSame('Week one reader (corrected)', $material->title);
        $this->assertNotSame($originalPath, $material->path);
        $this->assertSame($this->teacher->id, $material->uploader_id);

        $revision = DB::table('material_revisions')->where('material_id', $material->id)->first();
        $this->assertSame(1, (int) $revision->version);
        $this->assertSame($originalPath, $revision->path);
        $this->assertSame('reader.txt', $revision->original_name);
        $this->assertSame('Fixed a factual error on page two.', $revision->reason);
        $this->assertSame($this->teacher->id, (int) $revision->replaced_by);

        // Both the superseded and current files are retained on disk.
        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($material->path);

        $this->actingAs($this->teacher)->get(route('materials.revisions.download', $revision->id))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->learner)->get(route('materials.revisions.download', $revision->id))->assertForbidden();
        $this->actingAs($this->learner)->get(route('materials.download', $material))->assertOk();
    }

    public function test_stale_version_replacement_is_rejected_and_does_not_leave_an_orphaned_file(): void
    {
        $material = $this->upload();
        $before = Storage::disk('local')->allFiles();

        $this->actingAs($this->teacher)->post(route('materials.replace', $material), [
            'version' => 0,
            'title' => 'Stale replacement',
            'reason' => 'Working from an old form.',
            'file' => UploadedFile::fake()->createWithContent('stale.txt', 'stale'),
        ])->assertStatus(409);

        $this->assertSame(1, $material->fresh()->version);
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('material_revisions', 0);
    }

    public function test_archiving_removes_learner_access_and_blocks_new_notes_while_preserving_existing_ones(): void
    {
        $material = $this->upload();
        $note = StudyNote::create([
            'user_id' => $this->learner->id, 'course_id' => $this->course->id, 'material_id' => $material->id,
            'source_title' => $material->title, 'title' => 'Existing notes', 'request_key' => str_repeat('a', 64),
            'status' => 'completed', 'content' => 'Notes generated before archival.', 'provider' => 'mock',
        ]);

        $this->actingAs($this->teacher)->post(route('materials.archive', $material), [
            'version' => 1, 'action' => 'archive', 'reason' => 'Superseded by the 2026 edition.',
        ])->assertRedirect();

        $material->refresh();
        $this->assertSame('archived', $material->status);
        $this->assertNotNull($material->archived_at);
        $this->assertSame($this->teacher->id, $material->archived_by);

        $this->actingAs($this->learner)->get(route('materials.download', $material))->assertNotFound();
        $this->post(route('notes.store'), ['source_type' => 'material', 'source_id' => $material->id])->assertSessionHasErrors('source');
        $this->get(route('courses.show', $this->course))->assertOk()->assertDontSee('Week one reader');

        // Historical evidence survives archival.
        $this->assertDatabaseHas('study_notes', ['id' => $note->id, 'content' => 'Notes generated before archival.']);
        Storage::disk('local')->assertExists($material->path);
        $this->actingAs($this->teacher)->get(route('materials.download', $material))->assertOk();

        $this->post(route('materials.archive', $material), ['version' => 1, 'action' => 'restore'])->assertRedirect();
        $this->assertSame('active', $material->fresh()->status);
        $this->actingAs($this->learner)->get(route('materials.download', $material))->assertOk();
    }

    public function test_archived_materials_cannot_be_replaced_and_learners_cannot_manage_materials(): void
    {
        $material = $this->upload();
        $this->actingAs($this->teacher)->post(route('materials.archive', $material), [
            'version' => 1, 'action' => 'archive', 'reason' => 'Withdrawn.',
        ])->assertRedirect();

        $this->post(route('materials.replace', $material), [
            'version' => 1, 'title' => 'Nope', 'reason' => 'Should fail.',
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
        ])->assertStatus(409);

        $this->actingAs($this->learner)->post(route('materials.archive', $material), [
            'version' => 1, 'action' => 'restore',
        ])->assertForbidden();
        $this->post(route('materials.replace', $material), [
            'version' => 1, 'title' => 'Nope', 'reason' => 'Should fail.',
            'file' => UploadedFile::fake()->createWithContent('x.txt', 'x'),
        ])->assertForbidden();
        $this->assertSame('archived', $material->fresh()->status);
    }
}
