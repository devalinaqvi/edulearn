<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * A portable copy of a course's teaching content.
 *
 * This exists so that "delete this course" is a decision someone can take back. Deleting content
 * is irreversible by design once the dependency analyser allows it, and an institution should not
 * have to choose between keeping clutter forever and losing the work that went into a course.
 *
 * Learner data is deliberately absent. An export is a file that gets emailed, copied to a laptop
 * and forgotten about, so it carries no enrolments, submissions, attempts, grades or names —
 * nothing here is a lawful basis for moving somebody's assessment record onto a USB stick. The
 * records that matter are protected in the database instead, where deletion cannot reach them.
 */
class CourseExport
{
    /** Written into the archive so a reader knows what they are and are not holding. */
    private const NOTICE = 'Teaching content only. This export deliberately contains no learner '
        .'data: no enrolments, submissions, quiz attempts, grades or personal details. It is '
        .'intended as a copy of the course before archival or deletion, not as a student record.';

    /**
     * Build a zip archive at the given path and return a summary of what went into it.
     *
     * @return array{entries: array<string, int>, bytes: int}
     */
    public function write(Course $course, string $destination): array
    {
        $manifest = $this->manifest($course);

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The export file could not be created on this server.');
        }

        $zip->addFromString('course.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('README.txt', $this->readme($course));

        $disk = Storage::disk('local');
        $included = 0;
        foreach ($manifest['materials'] as $material) {
            // A row whose file has gone missing is listed in the manifest but cannot be shipped.
            if ($material['path'] && $disk->exists($material['path'])) {
                $zip->addFromString('materials/'.$material['export_name'], $disk->get($material['path']));
                $included++;
            }
        }

        $zip->close();

        return [
            'entries' => [
                'lessons' => count($manifest['lessons']),
                'materials' => $included,
                'assignments' => count($manifest['assignments']),
                'quizzes' => count($manifest['quizzes']),
                'announcements' => count($manifest['announcements']),
            ],
            'bytes' => (int) filesize($destination),
        ];
    }

    /** A filename that is safe on every platform and identifies the course. */
    public function filename(Course $course): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $course->code ?: $course->title) ?? 'course', '-');

        return 'course-'.strtolower($slug ?: 'export').'-'.now()->format('Ymd-His').'.zip';
    }

    /** @return array<string, mixed> */
    private function manifest(Course $course): array
    {
        return [
            'format' => 'edulearn.course-export/1',
            'exported_at' => now()->toIso8601String(),
            'notice' => self::NOTICE,
            'course' => [
                'code' => $course->code,
                'title' => $course->title,
                'description' => $course->description,
                'status' => $course->status,
            ],
            'lessons' => $course->lessons()->orderBy('position')->get()
                ->map(fn ($lesson) => [
                    'position' => $lesson->position,
                    'title' => $lesson->title,
                    'status' => $lesson->status,
                    'body_format' => $lesson->body_format,
                    'body' => $lesson->body,
                ])->all(),
            'materials' => $course->materials()->orderBy('id')->get()
                ->map(fn ($material) => [
                    'title' => $material->title,
                    'original_name' => $material->original_name,
                    'format' => $material->format,
                    'status' => $material->status,
                    'size_bytes' => $material->size_bytes,
                    'path' => $material->path,
                    'export_name' => $material->id.'-'.($material->original_name ?: 'material'),
                ])->all(),
            'assignments' => $course->assignments()->orderBy('id')->get()
                ->map(fn ($assignment) => [
                    'title' => $assignment->title,
                    'status' => $assignment->status,
                    'instructions' => $assignment->instructions,
                    'instructions_format' => $assignment->instructions_format,
                    'due_at' => optional($assignment->due_at)->toIso8601String(),
                    'max_marks' => $assignment->max_marks,
                    'rubric' => $assignment->rubric,
                ])->all(),
            'quizzes' => DB::table('quizzes')->where('course_id', $course->id)->orderBy('id')->get()
                ->map(fn ($quiz) => [
                    'title' => $quiz->title,
                    'instructions' => $quiz->instructions,
                    'status' => $quiz->status,
                    'opens_at' => $quiz->opens_at,
                    'closes_at' => $quiz->closes_at,
                    'duration_minutes' => $quiz->duration_minutes,
                    'questions' => json_decode($quiz->questions, true),
                ])->all(),
            'announcements' => $course->announcements()->orderBy('id')->get()
                ->map(fn ($announcement) => [
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'published_at' => optional($announcement->published_at)->toIso8601String(),
                ])->all(),
        ];
    }

    private function readme(Course $course): string
    {
        return implode("\n", [
            'EduLearn course export',
            '',
            'Course:      '.$course->title.($course->code ? ' ('.$course->code.')' : ''),
            'Exported:    '.now()->toDateTimeString().' UTC',
            '',
            wordwrap(self::NOTICE, 78),
            '',
            'course.json  structured content, including quiz answer keys',
            'materials/   the uploaded study material files',
            '',
            'Quiz answer keys are present in course.json. Treat this archive as staff-only.',
        ])."\n";
    }
}
