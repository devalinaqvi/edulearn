<?php

namespace App\Actions;

use App\Models\Assignment;
use App\Models\AssignmentMedium;
use App\Models\User;
use App\Services\VideoProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reference media attached to an assignment brief.
 *
 * Nothing here trusts what the browser says a file is. An image is accepted only if PHP can
 * actually decode its header, and a video only if its ISO-BMFF box tree parses as H.264/AAC MP4
 * — the same check lecture recordings go through, which is why no subprocess is spawned and no
 * uploaded filename ever reaches a shell. An executable renamed to .jpg fails both.
 *
 * Stored names are generated, so a crafted client filename cannot influence a path; the original
 * is kept only as metadata for display and download.
 */
class AssignmentMediaLibrary
{
    /** Image types the application will decode and serve back. */
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public function __construct(private VideoProbe $probe) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function attach(User $actor, Assignment $assignment, array $input, ?UploadedFile $file): AssignmentMedium
    {
        $data = Validator::make($input, [
            'kind' => 'required|in:image,video',
            'alt_text' => 'nullable|string|max:500',
        ])->validate();

        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload did not complete. Check your connection and try again.']);
        }
        if ($data['kind'] === 'image' && blank($data['alt_text'] ?? null)) {
            throw ValidationException::withMessages(['alt_text' => 'Describe the image so learners using a screen reader receive the same brief.']);
        }

        $stored = $data['kind'] === 'image' ? $this->storeImage($file) : $this->storeVideo($file);

        try {
            return DB::transaction(function () use ($actor, $assignment, $data, $file, $stored) {
                WriteLock::acquire();
                $current = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('manage', $current->course);

                $count = AssignmentMedium::where('assignment_id', $current->id)->count();
                abort_if($count >= config('lms.assignment_media.max_per_assignment'), 422, 'This assignment already has the maximum number of reference files.');

                return AssignmentMedium::create([
                    'assignment_id' => $current->id,
                    'kind' => $data['kind'],
                    'path' => $stored['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => (int) $file->getSize(),
                    'duration_seconds' => $stored['duration_seconds'] ?? null,
                    'alt_text' => $data['alt_text'] ?? null,
                    'position' => $count + 1,
                    'uploader_id' => $actor->id,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored['path']);
            throw $e;
        }
    }

    /** Remove a reference file and the bytes behind it; nothing else refers to them. */
    public function detach(User $actor, AssignmentMedium $medium): void
    {
        DB::transaction(function () use ($actor, $medium) {
            WriteLock::acquire();
            $current = AssignmentMedium::whereKey($medium->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('manage', $current->assignment->course);
            $path = $current->path;
            $current->delete();
            // Only once the row is certainly gone, so a rolled-back delete cannot orphan a record.
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        });
    }

    /** @return array{path: string, mime_type: string} */
    private function storeImage(UploadedFile $file): array
    {
        $this->assertSize($file, (int) config('lms.assignment_media.image_max_kilobytes'), 'image');

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false || ! isset(self::IMAGE_TYPES[$dimensions[2]])) {
            throw ValidationException::withMessages(['file' => 'The reference image must be a real JPEG, PNG or WebP file.']);
        }
        [$extension, $mime] = self::IMAGE_TYPES[$dimensions[2]];

        return ['path' => $this->put($file, 'assignment-media/images', $extension), 'mime_type' => $mime];
    }

    /** @return array{path: string, mime_type: string, duration_seconds: int} */
    private function storeVideo(UploadedFile $file): array
    {
        $this->assertSize($file, (int) config('lms.assignment_media.video_max_kilobytes'), 'video');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'm4v'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload an MP4 reference clip (H.264 video, AAC audio).']);
        }
        try {
            $probe = $this->probe->inspect($file->getRealPath());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return [
            'path' => $this->put($file, 'assignment-media/videos', 'mp4'),
            'mime_type' => 'video/mp4',
            'duration_seconds' => (int) $probe['duration_seconds'],
        ];
    }

    private function assertSize(UploadedFile $file, int $maxKilobytes, string $noun): void
    {
        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => 'A reference '.$noun.' must be smaller than '.round($maxKilobytes / 1024, 1).' MB.',
            ]);
        }
    }

    /** Generated name only: the client filename is metadata and never part of a path. */
    private function put(UploadedFile $file, string $directory, string $extension): string
    {
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk('local')->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }
}
