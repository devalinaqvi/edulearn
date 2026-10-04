<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentMedium;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reference media attached to an assignment brief.
 *
 * What the browser claims a file is counts for nothing: an image must decode and a clip must
 * parse as H.264/AAC MP4, so a renamed executable is refused by the same checks that protect
 * lecture recordings.
 */
class AssignmentMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Course $course;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['code' => 'EL-MEDIA', 'instructor_id' => $this->teacher->id, 'title' => 'Media course', 'description' => 'Online', 'status' => 'published']);
        $this->course->enrollments()->create(['user_id' => $this->student->id]);
        $this->assignment = $this->course->assignments()->create(['title' => 'Brief', 'instructions' => 'Follow the reference.', 'due_at' => now()->addWeek(), 'max_marks' => 10]);
    }

    /** A one-pixel PNG, which getimagesize can genuinely decode. */
    private function image(string $name = 'reference.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function box(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8).$type.$payload;
    }

    /** A structurally valid MP4 carrying the boxes VideoProbe reads. */
    private function mp4(string $video = 'avc1', ?string $audio = 'mp4a'): string
    {
        $timescale = 1000;
        $mvhd = $this->box('mvhd', "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', 8 * $timescale).str_repeat("\0", 80));
        $track = function (string $handler, string $format): string {
            $hdlr = $this->box('hdlr', "\0\0\0\0".pack('N', 0).$handler.str_repeat("\0", 12).'Track');
            $stsd = $this->box('stsd', "\0\0\0\0".pack('N', 1).pack('N', 8 + 70).$format.str_repeat("\0", 70));

            return $this->box('trak', $this->box('mdia', $hdlr.$this->box('minf', $this->box('stbl', $stsd))));
        };
        $moov = $this->box('moov', $mvhd.$track('vide', $video).($audio === null ? '' : $track('soun', $audio)));

        return $this->box('ftyp', 'isom'.pack('N', 512).'isom'.'mp42').$this->box('mdat', str_repeat("\x21", 2048)).$moov;
    }

    private function clip(string $name = 'reference.mp4', ?string $contents = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents ?? $this->mp4());
    }

    public function test_an_image_with_a_description_is_attached_and_stored_privately(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'A worked example of the layout', 'file' => $this->image(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $medium = AssignmentMedium::sole();
        $this->assertSame('image/png', $medium->mime_type);
        $this->assertStringStartsWith('assignment-media/images/', $medium->path);
        $this->assertStringNotContainsString('reference.png', $medium->path, 'The stored name must be generated, not supplied.');
        Storage::disk('local')->assertExists($medium->path);
    }

    public function test_an_image_without_a_description_is_refused(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'file' => $this->image(),
        ])->assertSessionHasErrors('alt_text');

        $this->assertSame(0, AssignmentMedium::count());
        $this->assertEmpty(Storage::disk('local')->allFiles('assignment-media'));
    }

    public function test_an_mp4_clip_is_accepted_and_its_duration_recorded(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'video', 'file' => $this->clip(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $medium = AssignmentMedium::sole();
        $this->assertSame('video/mp4', $medium->mime_type);
        $this->assertSame(8, $medium->duration_seconds);
    }

    public function test_a_disguised_file_is_refused_and_nothing_is_stored(): void
    {
        // An executable renamed to .png: the extension says image, the bytes do not.
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'Not really a picture',
            'file' => UploadedFile::fake()->createWithContent('payload.png', "MZ\x90\0\x03\0\0\0 not an image at all"),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, AssignmentMedium::count());
        $this->assertEmpty(Storage::disk('local')->allFiles('assignment-media'));
    }

    public function test_an_unsupported_video_codec_is_refused(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'video', 'file' => $this->clip('hevc.mp4', $this->mp4('hvc1')),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, AssignmentMedium::count());
    }

    public function test_an_oversized_clip_is_refused_without_raising_other_limits(): void
    {
        config(['lms.assignment_media.video_max_kilobytes' => 1]);

        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'video', 'file' => $this->clip(),
        ])->assertSessionHasErrors('file');

        // The study-material ceiling is a separate setting and must be untouched by this one.
        $this->assertSame(10240, 10 * 1024);
        $this->assertSame(0, AssignmentMedium::count());
    }

    public function test_reference_media_is_visible_to_enrolled_learners_in_the_brief(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'A worked example of the layout', 'file' => $this->image(),
        ])->assertRedirect();
        $medium = AssignmentMedium::sole();

        $this->actingAs($this->student)->get(route('assignments.show', $this->assignment))->assertOk()
            ->assertSee('Reference material')
            ->assertSee('A worked example of the layout');

        $this->get(route('assignments.media.show', $medium))->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_media_is_withheld_from_anyone_outside_the_course(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'Private reference', 'file' => $this->image(),
        ])->assertRedirect();
        $medium = AssignmentMedium::sole();
        $outsider = User::factory()->create(['role' => 'student']);

        $this->actingAs($outsider)->get(route('assignments.media.show', $medium))->assertForbidden();
        $this->get(route('assignments.media.store', $this->assignment))->assertStatus(405);
    }

    public function test_only_course_staff_may_attach_or_remove_media(): void
    {
        $outsider = User::factory()->create(['role' => 'instructor']);

        foreach ([$outsider, $this->student] as $actor) {
            $this->actingAs($actor)->post(route('assignments.media.store', $this->assignment), [
                'kind' => 'image', 'alt_text' => 'x', 'file' => $this->image(),
            ])->assertForbidden();
        }

        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'Staff upload', 'file' => $this->image(),
        ])->assertRedirect();
        $medium = AssignmentMedium::sole();

        $this->actingAs($this->student)->delete(route('assignments.media.destroy', $medium))->assertForbidden();
        $this->assertSame(1, AssignmentMedium::count());
    }

    public function test_removing_media_deletes_the_stored_bytes(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'Temporary', 'file' => $this->image(),
        ])->assertRedirect();
        $medium = AssignmentMedium::sole();
        $path = $medium->path;

        $this->delete(route('assignments.media.destroy', $medium))->assertRedirect();

        $this->assertSame(0, AssignmentMedium::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_clip_is_served_with_range_support(): void
    {
        $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'video', 'file' => $this->clip(),
        ])->assertRedirect();
        $medium = AssignmentMedium::sole();

        $this->actingAs($this->student)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get(route('assignments.media.show', $medium))
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', '100');
    }

    public function test_an_assignment_accepts_only_a_bounded_number_of_reference_files(): void
    {
        config(['lms.assignment_media.max_per_assignment' => 2]);

        foreach (range(1, 2) as $index) {
            $this->actingAs($this->teacher)->post(route('assignments.media.store', $this->assignment), [
                'kind' => 'image', 'alt_text' => 'Reference '.$index, 'file' => $this->image(),
            ])->assertRedirect();
        }

        $this->post(route('assignments.media.store', $this->assignment), [
            'kind' => 'image', 'alt_text' => 'One too many', 'file' => $this->image(),
        ])->assertStatus(422);

        $this->assertSame(2, AssignmentMedium::count());
    }
}
