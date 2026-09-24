<?php

namespace App\Actions;

use App\Models\Course;
use App\Models\User;
use App\Models\VideoLecture;
use App\Models\VideoLectureProgress;
use App\Models\VideoLectureTrack;
use App\Services\VideoProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Authoring, replacement, publication and progress rules for course video lectures.
 *
 * All mutations acquire lms_write_locks row 1 first, matching the ordering documented in
 * .ai/rules/app.md for assessment, enrollment and permission mutations.
 */
class VideoLectureWorkflow
{
    /**
     * Largest playback-rate multiple credited against real elapsed time. The player offers up
     * to 2x; the margin absorbs timer jitter.
     */
    private const MAX_PLAYBACK_RATE = 2.5;

    /**
     * Credit ceiling for a learner's first report, when there is no previous report to measure
     * from. Also capped at a fraction of the lecture (see FIRST_REPORT_RATIO) so that on a very
     * short lecture a single report can never on its own reach the completion threshold.
     */
    private const FIRST_REPORT_SECONDS = 20;

    private const FIRST_REPORT_RATIO = 0.2;

    public function __construct(private VideoProbe $probe) {}

    /**
     * Validate the uploaded media, store it privately, and create a draft lecture.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, Course $course, array $input, UploadedFile $file): VideoLecture
    {
        $data = Validator::make($input, [
            'title' => 'required|string|max:160',
            'description' => 'nullable|string|max:5000',
            'lesson_id' => 'nullable|integer',
            'position' => 'nullable|integer|min:1|max:10000',
        ])->validate();
        $this->assertLesson($course, $data['lesson_id'] ?? null);

        $media = $this->storeMedia($file);

        try {
            return DB::transaction(function () use ($actor, $course, $data, $file, $media) {
                $this->lock();
                Gate::forUser($actor->fresh())->authorize('manage', $course->fresh());

                return VideoLecture::create([
                    'course_id' => $course->id,
                    'lesson_id' => $data['lesson_id'] ?? null,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'position' => $data['position'] ?? ((int) VideoLecture::where('course_id', $course->id)->max('position') + 1),
                    'status' => 'draft',
                    'processing_status' => 'ready',
                    'path' => $media['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => 'video/mp4',
                    'container' => $media['probe']['container'],
                    'video_codec' => $media['probe']['video_codec'],
                    'audio_codec' => $media['probe']['audio_codec'],
                    'size_bytes' => $media['size'],
                    'duration_seconds' => $media['probe']['duration_seconds'],
                    'uploader_id' => $actor->id,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($media['path']);
            throw $e;
        }
    }

    /**
     * Replace a lecture's media, retaining the superseded file and its metadata.
     *
     * @param  array<string, mixed>  $input
     */
    public function replaceMedia(User $actor, VideoLecture $lecture, array $input, UploadedFile $file): void
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'reason' => 'required|string|max:1000',
        ])->validate();

        $media = $this->storeMedia($file);

        try {
            DB::transaction(function () use ($actor, $lecture, $data, $file, $media) {
                $this->lock();
                $current = VideoLecture::whereKey($lecture->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('manage', $current->course);
                abort_if((int) $data['version'] !== $current->version, 409, 'This lecture changed. Reload before replacing its media.');

                DB::table('video_lecture_revisions')->insert([
                    'video_lecture_id' => $current->id,
                    'version' => $current->version,
                    'path' => $current->path,
                    'original_name' => $current->original_name,
                    'mime_type' => $current->mime_type,
                    'size_bytes' => $current->size_bytes,
                    'duration_seconds' => $current->duration_seconds,
                    'uploader_id' => $current->uploader_id,
                    'uploaded_at' => $current->uploaded_at,
                    'replaced_by' => $actor->id,
                    'replaced_at' => now(),
                    'reason' => $data['reason'],
                ]);

                $current->update([
                    'path' => $media['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => 'video/mp4',
                    'container' => $media['probe']['container'],
                    'video_codec' => $media['probe']['video_codec'],
                    'audio_codec' => $media['probe']['audio_codec'],
                    'size_bytes' => $media['size'],
                    'duration_seconds' => $media['probe']['duration_seconds'],
                    'processing_status' => 'ready',
                    'processing_error' => null,
                    'uploader_id' => $actor->id,
                    'uploaded_at' => now(),
                    'version' => $current->version + 1,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($media['path']);
            throw $e;
        }
    }

    /**
     * Move a lecture between draft, published and archived.
     *
     * @param  array<string, mixed>  $input
     */
    public function changeStatus(User $actor, VideoLecture $lecture, array $input): void
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'status' => 'required|in:draft,published,archived',
            'reason' => 'nullable|string|max:1000',
        ])->validate();

        DB::transaction(function () use ($actor, $lecture, $data) {
            $this->lock();
            $current = VideoLecture::whereKey($lecture->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->course);
            abort_if((int) $data['version'] !== $current->version, 409, 'This lecture changed. Reload before continuing.');
            if ($current->status === $data['status']) {
                return; // Idempotent.
            }
            abort_if($data['status'] === 'published' && $current->processing_status !== 'ready', 422, 'This lecture cannot be published until its media is ready to play.');

            $current->update([
                'status' => $data['status'],
                'published_at' => $data['status'] === 'published' ? now() : $current->published_at,
                'published_by' => $data['status'] === 'published' ? $actor->id : $current->published_by,
                'archived_at' => $data['status'] === 'archived' ? now() : null,
                'archived_by' => $data['status'] === 'archived' ? $actor->id : null,
            ]);
        });
    }

    /** @param array<string, mixed> $input */
    public function updateDetails(User $actor, VideoLecture $lecture, array $input): void
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'title' => 'required|string|max:160',
            'description' => 'nullable|string|max:5000',
            'lesson_id' => 'nullable|integer',
            'position' => 'required|integer|min:1|max:10000',
        ])->validate();

        DB::transaction(function () use ($actor, $lecture, $data) {
            $this->lock();
            $current = VideoLecture::whereKey($lecture->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->course);
            abort_if((int) $data['version'] !== $current->version, 409, 'This lecture changed. Reload before saving.');
            $this->assertLesson($current->course, $data['lesson_id'] ?? null);
            $current->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'lesson_id' => $data['lesson_id'] ?? null,
                'position' => $data['position'],
            ]);
        });
    }

    /**
     * Save a captions file (validated WebVTT) or a plain-text transcript.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveTrack(User $actor, VideoLecture $lecture, array $input, ?UploadedFile $file): void
    {
        $data = Validator::make($input, [
            'kind' => 'required|in:captions,transcript',
            'language' => 'required|string|max:16|regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/',
            'label' => 'required|string|max:100',
            'transcript_text' => 'required_if:kind,transcript|nullable|string|max:'.config('video.transcript_max_chars'),
        ])->validate();

        $path = null;
        if ($data['kind'] === 'captions') {
            if (! $file || ! $file->isValid()) {
                throw ValidationException::withMessages(['file' => 'Choose a WebVTT captions file.']);
            }
            if ($file->getSize() > config('video.caption_max_kilobytes') * 1024) {
                throw ValidationException::withMessages(['file' => 'Captions must be smaller than '.config('video.caption_max_kilobytes').' KB.']);
            }
            $contents = file_get_contents($file->getRealPath());
            $this->assertWebVtt($contents);
            $path = 'video-lectures/captions/'.bin2hex(random_bytes(16)).'.vtt';
            Storage::disk('local')->put($path, $contents);
        }

        try {
            DB::transaction(function () use ($actor, $lecture, $data, $path) {
                $this->lock();
                $current = VideoLecture::whereKey($lecture->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('manage', $current->course);
                $track = VideoLectureTrack::firstOrNew([
                    'video_lecture_id' => $current->id,
                    'kind' => $data['kind'],
                    'language' => strtolower($data['language']),
                ]);
                $previous = $track->path;
                $track->fill([
                    'label' => $data['label'],
                    'path' => $path ?? $track->path,
                    'transcript_text' => $data['kind'] === 'transcript' ? $data['transcript_text'] : null,
                    'uploader_id' => $actor->id,
                    'version' => ($track->version ?? 0) + 1,
                ])->save();
                if ($path && $previous && $previous !== $path) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($previous));
                }
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Store an optional poster image. Posters are uploaded rather than generated from a frame,
     * which would require FFmpeg.
     */
    public function savePoster(User $actor, VideoLecture $lecture, UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() > config('video.poster_max_kilobytes') * 1024) {
            throw ValidationException::withMessages(['poster' => 'Choose a JPEG, PNG or WebP image under '.config('video.poster_max_kilobytes').' KB.']);
        }
        $dimensions = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($dimensions === false || ! in_array($dimensions[2], $allowed, true)) {
            throw ValidationException::withMessages(['poster' => 'The poster must be a real JPEG, PNG or WebP image.']);
        }
        $extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$dimensions[2]];
        $path = 'video-lectures/posters/'.bin2hex(random_bytes(16)).'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk('local')->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            DB::transaction(function () use ($actor, $lecture, $path) {
                $this->lock();
                $current = VideoLecture::whereKey($lecture->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('manage', $current->course);
                $previous = $current->poster_path;
                $current->update(['poster_path' => $path]);
                if ($previous) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($previous));
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * Record a learner's playback position and genuinely-watched time.
     *
     * Watched time is the minimum of three independent bounds, so no client claim alone can
     * inflate it:
     *   1. the delta the player reports;
     *   2. new forward ground — timeline past the furthest point previously reached, so
     *      replaying the same segment never double counts;
     *   3. real elapsed wall-clock time since this learner's previous report for this lecture,
     *      multiplied by the fastest supported playback rate.
     *
     * Bound 3 is what stops a seek to the end from completing a lecture: one jump reports a
     * large position but no time has passed, so almost no credit is earned.
     *
     * @param  array<string, mixed>  $input
     * @return array{position_seconds: int, watched_seconds: int, completed: bool}
     */
    public function recordProgress(User $actor, VideoLecture $lecture, array $input): array
    {
        $duration = max(1, (int) $lecture->duration_seconds);
        $data = Validator::make($input, [
            'position_seconds' => 'required|integer|min:0|max:'.$duration,
            'watched_delta' => 'nullable|integer|min:0|max:120',
        ])->validate();

        return DB::transaction(function () use ($actor, $lecture, $data, $duration) {
            $progress = VideoLectureProgress::where('video_lecture_id', $lecture->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first()
                ?? new VideoLectureProgress(['video_lecture_id' => $lecture->id, 'user_id' => $actor->id]);

            $position = min((int) $data['position_seconds'], $duration);
            $previousFurthest = (int) ($progress->furthest_seconds ?? 0);
            $furthest = max($previousFurthest, $position);

            $allowance = $progress->exists && $progress->last_reported_at
                ? (int) floor(max(0, now()->getTimestamp() - $progress->last_reported_at->getTimestamp()) * self::MAX_PLAYBACK_RATE)
                : min(self::FIRST_REPORT_SECONDS, max(1, (int) floor($duration * self::FIRST_REPORT_RATIO)));

            $credit = max(0, min((int) ($data['watched_delta'] ?? 0), $furthest - $previousFurthest, $allowance));
            $watched = min($duration, (int) ($progress->watched_seconds ?? 0) + $credit);

            $completed = (bool) ($progress->completed ?? false) || $watched >= (int) floor($duration * config('video.completion_ratio'));

            $progress->fill([
                'position_seconds' => $position,
                'furthest_seconds' => $furthest,
                'watched_seconds' => $watched,
                'completed' => $completed,
                'completed_at' => $completed ? ($progress->completed_at ?? now()) : null,
                'last_reported_at' => now(),
            ])->save();

            return ['position_seconds' => $position, 'watched_seconds' => $watched, 'completed' => $completed];
        });
    }

    /**
     * Store an uploaded video under a generated filename after validating its actual contents.
     *
     * @return array{path: string, size: int, probe: array<string, mixed>}
     */
    private function storeMedia(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload did not complete. Check your connection and try again.']);
        }
        $maxBytes = config('video.max_kilobytes') * 1024;
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Videos must be smaller than '.round($maxBytes / 1048576).' MB.']);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'm4v'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload an MP4 lecture recording (H.264 video, AAC audio).']);
        }

        try {
            $probe = $this->probe->inspect($file->getRealPath());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        // Generated name only: the client filename is retained as metadata and never used as a path.
        $path = 'video-lectures/'.bin2hex(random_bytes(16)).'.mp4';
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk('local')->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return ['path' => $path, 'size' => (int) $file->getSize(), 'probe' => $probe];
    }

    private function assertLesson(Course $course, int|string|null $lessonId): void
    {
        if ($lessonId === null || $lessonId === '') {
            return;
        }
        if (! $course->lessons()->whereKey($lessonId)->exists()) {
            throw ValidationException::withMessages(['lesson_id' => 'Select a lesson that belongs to this course.']);
        }
    }

    /** Reject anything that is not a WebVTT cue file before it is ever served to a browser. */
    private function assertWebVtt(string $contents): void
    {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (! preg_match('/^WEBVTT(\r?\n|\s|$)/', $normalized)) {
            throw ValidationException::withMessages(['file' => 'Captions must be a WebVTT file beginning with "WEBVTT".']);
        }
        if (! mb_check_encoding($normalized, 'UTF-8') || str_contains($normalized, "\0")) {
            throw ValidationException::withMessages(['file' => 'Captions must contain readable UTF-8 text.']);
        }
        if (! preg_match('/\d{2}:\d{2}[:.]\d{2}[.,]\d{3}\s*-->/', $normalized)) {
            throw ValidationException::withMessages(['file' => 'Captions must contain at least one timed cue.']);
        }
    }

    private function lock(): void
    {
        DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
    }
}
