<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyNote;
use App\Models\User;
use App\Models\VideoLecture;
use App\Models\VideoLectureProgress;
use App\Models\VideoLectureTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoLectureTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $otherTeacher;

    private User $learner;

    private User $outsider;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->otherTeacher = User::factory()->create(['role' => 'instructor']);
        $this->learner = User::factory()->create(['role' => 'student']);
        $this->outsider = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['instructor_id' => $this->teacher->id, 'title' => 'Video course', 'description' => 'Online', 'status' => 'published']);
        Enrollment::create(['course_id' => $this->course->id, 'user_id' => $this->learner->id]);
    }

    // ---------------------------------------------------------------- fixtures

    private function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /**
     * Build a structurally valid MP4 (ISO/IEC 14496-12) with the boxes VideoProbe reads.
     */
    private function mp4(int $seconds = 10, string $video = 'avc1', ?string $audio = 'mp4a', string $brand = 'isom', int $padding = 2048): string
    {
        $timescale = 1000;
        $mvhd = $this->box('mvhd', "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', $seconds * $timescale).str_repeat("\0", 80));

        $track = function (string $handler, string $format): string {
            $hdlr = $this->box('hdlr', "\0\0\0\0".pack('N', 0).$handler.str_repeat("\0", 12).'Track');
            $entry = pack('N', 8 + 70).$format.str_repeat("\0", 70);
            $stsd = $this->box('stsd', "\0\0\0\0".pack('N', 1).$entry);
            $stbl = $this->box('stbl', $stsd);
            $minf = $this->box('minf', $stbl);
            $mdia = $this->box('mdia', $hdlr.$minf);

            return $this->box('trak', $mdia);
        };

        $traks = $track('vide', $video).($audio === null ? '' : $track('soun', $audio));
        $moov = $this->box('moov', $mvhd.$traks);
        $ftyp = $this->box('ftyp', $brand.pack('N', 512).$brand.'mp42');
        $mdat = $this->box('mdat', str_repeat("\x21", $padding));

        return $ftyp.$mdat.$moov;
    }

    private function video(string $name = 'lecture.mp4', ?string $contents = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents ?? $this->mp4());
    }

    private function publishedLecture(int $seconds = 10): VideoLecture
    {
        $this->actingAs($this->teacher)->post(route('lectures.store', $this->course), [
            'title' => 'Week one lecture', 'file' => $this->video('week-one.mp4', $this->mp4($seconds)),
        ])->assertRedirect();
        $lecture = VideoLecture::latest('id')->firstOrFail();
        $this->post(route('lectures.status', $lecture), ['version' => $lecture->version, 'status' => 'published'])->assertRedirect();

        return $lecture->fresh();
    }

    // ---------------------------------------------------------------- upload & validation

    public function test_upload_validates_real_media_and_records_probe_metadata(): void
    {
        $this->actingAs($this->teacher)->post(route('lectures.store', $this->course), [
            'title' => 'Week one lecture',
            'description' => 'Introduction to the course.',
            'file' => $this->video('Week One Lecture.mp4', $this->mp4(125)),
        ])->assertRedirect();

        $lecture = VideoLecture::latest('id')->firstOrFail();
        $this->assertSame('draft', $lecture->status);
        $this->assertSame('ready', $lecture->processing_status);
        $this->assertSame('h264', $lecture->video_codec);
        $this->assertSame('aac', $lecture->audio_codec);
        $this->assertSame(125, $lecture->duration_seconds);
        $this->assertSame('Week One Lecture.mp4', $lecture->original_name);
        $this->assertSame($this->teacher->id, $lecture->uploader_id);
        // Generated storage name, never the client filename.
        $this->assertStringNotContainsString('Week One Lecture', $lecture->path);
        $this->assertMatchesRegularExpression('#^video-lectures/[0-9a-f]{32}\.mp4$#', $lecture->path);
        Storage::disk('local')->assertExists($lecture->path);
    }

    public function test_invalid_corrupted_and_unsupported_media_is_rejected_without_storing_anything(): void
    {
        $cases = [
            'not a video at all' => UploadedFile::fake()->createWithContent('notes.mp4', 'this is plain text, not media'),
            'wrong extension' => UploadedFile::fake()->createWithContent('lecture.mkv', $this->mp4()),
            'unsupported video codec' => UploadedFile::fake()->createWithContent('hevc.mp4', $this->mp4(10, 'hvc1')),
            'unsupported audio codec' => UploadedFile::fake()->createWithContent('opus.mp4', $this->mp4(10, 'avc1', 'Opus')),
            'no video track' => UploadedFile::fake()->createWithContent('audio.mp4', $this->mp4(10, 'mp4a', null)),
            'truncated file' => UploadedFile::fake()->createWithContent('cut.mp4', substr($this->mp4(), 0, 40)),
            'zero duration' => UploadedFile::fake()->createWithContent('empty.mp4', $this->mp4(0)),
        ];

        foreach ($cases as $label => $file) {
            $this->actingAs($this->teacher)
                ->post(route('lectures.store', $this->course), ['title' => 'Bad upload', 'file' => $file])
                ->assertSessionHasErrors('file');
            $this->assertDatabaseCount('video_lectures', 0);
            $this->flushSession();
        }

        $this->assertDatabaseCount('video_lectures', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_video_is_rejected_without_raising_the_document_limit(): void
    {
        config(['video.max_kilobytes' => 64]);
        $this->actingAs($this->teacher)->post(route('lectures.store', $this->course), [
            'title' => 'Too large', 'file' => $this->video('big.mp4', $this->mp4(10, 'avc1', 'mp4a', 'isom', 128 * 1024)),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('video_lectures', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The video limit is its own setting; the 10 MB document limit is untouched by it.
        $this->assertSame(64, (int) config('video.max_kilobytes'));
        $this->actingAs($this->teacher)->post(route('materials.store', $this->course), [
            'title' => 'Still ten megabytes', 'file' => UploadedFile::fake()->create('big.txt', 10241, 'text/plain'),
        ])->assertSessionHasErrors('file');
    }

    // ---------------------------------------------------------------- authorization

    public function test_lectures_are_isolated_to_their_own_course_staff(): void
    {
        $lecture = $this->publishedLecture();

        $this->actingAs($this->otherTeacher);
        $this->get(route('lectures.show', $lecture))->assertForbidden();
        $this->get(route('lectures.stream', $lecture))->assertForbidden();
        $this->post(route('lectures.status', $lecture), ['version' => $lecture->version, 'status' => 'archived'])->assertForbidden();
        $this->post(route('lectures.media', $lecture), ['version' => $lecture->version, 'reason' => 'x', 'file' => $this->video()])->assertForbidden();
        $this->assertSame('published', $lecture->fresh()->status);
    }

    public function test_learners_need_enrollment_publication_and_an_active_account(): void
    {
        $this->actingAs($this->teacher)->post(route('lectures.store', $this->course), [
            'title' => 'Draft lecture', 'file' => $this->video(),
        ])->assertRedirect();
        $draft = VideoLecture::latest('id')->firstOrFail();

        // Draft: staff can watch, enrolled learner cannot.
        $this->actingAs($this->teacher)->get(route('lectures.stream', $draft))->assertOk();
        $this->actingAs($this->learner)->get(route('lectures.show', $draft))->assertNotFound();
        $this->get(route('lectures.stream', $draft))->assertNotFound();

        $this->actingAs($this->teacher)->post(route('lectures.status', $draft), ['version' => $draft->version, 'status' => 'published'])->assertRedirect();

        // Published: enrolled learner can watch, a non-enrolled learner cannot.
        $this->actingAs($this->learner)->get(route('lectures.stream', $draft))->assertOk();
        $this->actingAs($this->outsider)->get(route('lectures.show', $draft))->assertForbidden();
        $this->get(route('lectures.stream', $draft))->assertForbidden();

        // Archived: learner access is withdrawn again.
        $this->actingAs($this->teacher)->post(route('lectures.status', $draft), ['version' => $draft->fresh()->version, 'status' => 'archived'])->assertRedirect();
        $this->actingAs($this->learner)->get(route('lectures.stream', $draft))->assertNotFound();
    }

    public function test_deactivated_learners_and_revoked_enrollments_lose_playback(): void
    {
        $lecture = $this->publishedLecture();
        $this->actingAs($this->learner)->get(route('lectures.stream', $lecture))->assertOk();

        Enrollment::where('course_id', $this->course->id)->where('user_id', $this->learner->id)->delete();
        $this->actingAs($this->learner)->get(route('lectures.stream', $lecture))->assertForbidden();

        Enrollment::create(['course_id' => $this->course->id, 'user_id' => $this->learner->id]);
        $this->learner->forceFill(['is_active' => false])->save();
        $this->actingAs($this->learner->fresh())->get(route('lectures.stream', $lecture))->assertRedirect();
    }

    // ---------------------------------------------------------------- range delivery

    public function test_private_delivery_supports_range_head_and_rejects_invalid_ranges(): void
    {
        $lecture = $this->publishedLecture();
        $size = $lecture->size_bytes;
        $this->actingAs($this->learner);

        $full = $this->get(route('lectures.stream', $lecture));
        $full->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($size, strlen($full->streamedContent()));

        $partial = $this->withHeaders(['Range' => 'bytes=0-99'])->get(route('lectures.stream', $lecture));
        $partial->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-99/'.$size)->assertHeader('Content-Length', '100');
        $this->assertSame(100, strlen($partial->streamedContent()));

        // Open-ended and suffix ranges.
        $this->withHeaders(['Range' => 'bytes=100-'])->get(route('lectures.stream', $lecture))
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-'.($size - 1).'/'.$size);
        $this->withHeaders(['Range' => 'bytes=-50'])->get(route('lectures.stream', $lecture))
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes '.($size - 50).'-'.($size - 1).'/'.$size);

        // Unsatisfiable ranges.
        foreach (['bytes='.$size.'-', 'bytes='.($size + 10).'-'.($size + 20), 'bytes=-0'] as $bad) {
            $this->withHeaders(['Range' => $bad])->get(route('lectures.stream', $lecture))
                ->assertStatus(416)->assertHeader('Content-Range', 'bytes */'.$size);
        }

        // A malformed range is ignored rather than failing the request.
        $this->withHeaders(['Range' => 'pages=1-2'])->get(route('lectures.stream', $lecture))->assertOk();

        $head = $this->head(route('lectures.stream', $lecture));
        $head->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', (string) $size);
    }

    // ---------------------------------------------------------------- replacement & revisions

    public function test_replacement_retains_the_previous_recording_for_staff_only(): void
    {
        $lecture = $this->publishedLecture(10);
        $originalPath = $lecture->path;

        $this->actingAs($this->teacher)->post(route('lectures.media', $lecture), [
            'version' => $lecture->version, 'reason' => 'Re-recorded with corrected audio.',
            'file' => $this->video('week-one-v2.mp4', $this->mp4(42)),
        ])->assertRedirect();

        $lecture->refresh();
        $this->assertSame(2, $lecture->version);
        $this->assertSame(42, $lecture->duration_seconds);
        $this->assertNotSame($originalPath, $lecture->path);

        $revision = DB::table('video_lecture_revisions')->where('video_lecture_id', $lecture->id)->first();
        $this->assertSame(1, (int) $revision->version);
        $this->assertSame($originalPath, $revision->path);
        Storage::disk('local')->assertExists($originalPath);

        $this->get(route('lectures.revisions.download', $revision->id))->assertOk();
        $this->actingAs($this->learner)->get(route('lectures.revisions.download', $revision->id))->assertForbidden();

        // A stale version is refused and leaves no orphaned upload.
        $before = Storage::disk('local')->allFiles();
        $this->actingAs($this->teacher)->post(route('lectures.media', $lecture), [
            'version' => 1, 'reason' => 'Stale form.', 'file' => $this->video(),
        ])->assertStatus(409);
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame(2, $lecture->fresh()->version);
    }

    // ---------------------------------------------------------------- captions & transcripts

    public function test_captions_are_validated_and_served_only_to_authorized_viewers(): void
    {
        $lecture = $this->publishedLecture();
        $vtt = "WEBVTT\n\n1\n00:00:00.000 --> 00:00:04.000\nWelcome to week one.\n";

        $this->actingAs($this->teacher)->post(route('lectures.tracks', $lecture), [
            'kind' => 'captions', 'language' => 'en', 'label' => 'English',
            'file' => UploadedFile::fake()->createWithContent('captions.vtt', $vtt),
        ])->assertRedirect();

        $track = VideoLectureTrack::where('video_lecture_id', $lecture->id)->where('kind', 'captions')->firstOrFail();
        $this->actingAs($this->learner)->get(route('lectures.captions', [$lecture, $track]))
            ->assertOk()->assertHeader('Content-Type', 'text/vtt; charset=UTF-8')->assertSee('Welcome to week one.');
        $this->actingAs($this->outsider)->get(route('lectures.captions', [$lecture, $track]))->assertForbidden();

        // Non-WebVTT payloads never reach storage.
        foreach (['<script>alert(1)</script>', "WEBVTT\n\nno cues here"] as $bad) {
            $this->actingAs($this->teacher)->post(route('lectures.tracks', $lecture), [
                'kind' => 'captions', 'language' => 'en', 'label' => 'English',
                'file' => UploadedFile::fake()->createWithContent('bad.vtt', $bad),
            ])->assertSessionHasErrors('file');
            $this->flushSession();
        }
        $this->assertSame(1, VideoLectureTrack::where('kind', 'captions')->count());
    }

    public function test_authorized_transcript_generates_study_notes_and_unpublished_lectures_do_not(): void
    {
        $lecture = $this->publishedLecture();
        $this->actingAs($this->teacher)->post(route('lectures.tracks', $lecture), [
            'kind' => 'transcript', 'language' => 'en', 'label' => 'English transcript',
            'transcript_text' => 'In this lecture we cover normalisation, primary keys and foreign keys.',
        ])->assertRedirect();

        $this->actingAs($this->learner)->post(route('notes.store'), ['source_type' => 'lecture', 'source_id' => $lecture->id])->assertRedirect();
        $note = StudyNote::where('user_id', $this->learner->id)->firstOrFail();
        $this->assertSame($lecture->id, $note->video_lecture_id);
        $this->assertSame('lecture', $note->sourceType());
        $this->assertSame('completed', $note->status);
        $this->assertStringContainsString('normalisation', $note->content);

        // A learner outside the course cannot use the transcript as a source.
        $this->actingAs($this->outsider)->post(route('notes.store'), ['source_type' => 'lecture', 'source_id' => $lecture->id])->assertForbidden();

        // A lecture without a transcript reports that speech-to-text is unavailable.
        $other = $this->publishedLecture();
        $this->actingAs($this->learner)->post(route('notes.store'), ['source_type' => 'lecture', 'source_id' => $other->id])
            ->assertSessionHasErrors('source');
    }

    // ---------------------------------------------------------------- progress

    public function test_progress_is_private_resumable_and_not_satisfied_by_seeking_to_the_end(): void
    {
        $lecture = $this->publishedLecture(100);

        // Seeking straight to the end reports a large position but earns almost no credit:
        // no wall-clock time has passed, so the claimed 99 seconds cannot be honoured.
        $this->actingAs($this->learner)->postJson(route('lectures.progress', $lecture), ['position_seconds' => 99, 'watched_delta' => 99])
            ->assertOk()->assertJson(['position_seconds' => 99, 'completed' => false]);
        $progress = VideoLectureProgress::where('user_id', $this->learner->id)->firstOrFail();
        $this->assertSame(99, $progress->position_seconds);
        $this->assertLessThanOrEqual(20, $progress->watched_seconds);
        $this->assertFalse($progress->completed);

        // Replaying ground already covered adds nothing, even after real time passes.
        $watched = $progress->watched_seconds;
        for ($i = 0; $i < 3; $i++) {
            $this->travel(30)->seconds();
            $this->postJson(route('lectures.progress', $lecture), ['position_seconds' => 40, 'watched_delta' => 40])->assertOk();
        }
        $this->assertSame($watched, VideoLectureProgress::where('user_id', $this->learner->id)->firstOrFail()->watched_seconds);
        $this->travelBack();

        // Progress is per learner.
        $this->assertDatabaseCount('video_lecture_progress', 1);
        Enrollment::create(['course_id' => $this->course->id, 'user_id' => $this->outsider->id]);
        $this->actingAs($this->outsider)->postJson(route('lectures.progress', $lecture), ['position_seconds' => 5, 'watched_delta' => 5])->assertOk();
        $this->assertSame(5, VideoLectureProgress::where('user_id', $this->outsider->id)->firstOrFail()->position_seconds);
        $mine = VideoLectureProgress::where('user_id', $this->learner->id)->firstOrFail();
        $this->assertSame(40, $mine->position_seconds, 'the playhead follows the learner back');
        $this->assertSame(99, $mine->furthest_seconds, 'but the furthest point reached is never lost');

        // Out-of-range updates are rejected.
        $this->actingAs($this->learner)->postJson(route('lectures.progress', $lecture), ['position_seconds' => 100000, 'watched_delta' => 5])->assertStatus(422);
        $this->postJson(route('lectures.progress', $lecture), ['position_seconds' => -1, 'watched_delta' => 5])->assertStatus(422);
    }

    public function test_continuous_forward_playback_completes_a_lecture(): void
    {
        $lecture = $this->publishedLecture(100);
        $this->actingAs($this->learner);

        // Real playback: ten seconds of wall clock for every ten seconds of content.
        for ($second = 10; $second <= 90; $second += 10) {
            $this->postJson(route('lectures.progress', $lecture), ['position_seconds' => $second, 'watched_delta' => 10])->assertOk();
            $this->travel(10)->seconds();
        }
        $this->travelBack();

        $progress = VideoLectureProgress::where('user_id', $this->learner->id)->firstOrFail();
        $this->assertSame(90, $progress->watched_seconds);
        $this->assertTrue($progress->completed);
        $this->assertNotNull($progress->completed_at);

        // Staff see learner progress; a learner cannot see another learner's record.
        $this->actingAs($this->teacher)->get(route('lectures.show', $lecture))->assertOk()->assertSee($this->learner->name);
        $this->actingAs($this->outsider)->get(route('lectures.show', $lecture))->assertForbidden();
    }

    /**
     * Regression: on a short lecture the first-report allowance must not by itself reach the
     * completion threshold. A live check found a 12 second lecture completing from one seek.
     */
    public function test_a_single_seek_cannot_complete_even_a_very_short_lecture(): void
    {
        foreach ([8, 12, 25, 60] as $seconds) {
            $lecture = $this->publishedLecture($seconds);
            $this->actingAs($this->learner)
                ->postJson(route('lectures.progress', $lecture), ['position_seconds' => $seconds - 1, 'watched_delta' => $seconds])
                ->assertOk()->assertJson(['completed' => false]);

            $progress = VideoLectureProgress::where('video_lecture_id', $lecture->id)->firstOrFail();
            $this->assertLessThan((int) floor($seconds * config('video.completion_ratio')), $progress->watched_seconds);
        }
    }

    public function test_instructors_do_not_record_viewing_progress(): void
    {
        $lecture = $this->publishedLecture();
        $this->actingAs($this->teacher)->postJson(route('lectures.progress', $lecture), ['position_seconds' => 5])->assertForbidden();
        $this->assertDatabaseCount('video_lecture_progress', 0);
    }
}
