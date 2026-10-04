<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Answers whether a record can be destroyed without taking evidence with it.
 *
 * The question is settled against the real foreign-key graph, read from the schema rather than
 * from a list maintained by hand, so a table added by a later migration is accounted for the day
 * it appears instead of silently becoming an unchecked blocker.
 *
 * Three kinds of reference behave differently and must not be conflated:
 *   - RESTRICT / NO ACTION: the database will refuse the delete. These are blockers.
 *   - SET NULL: the child survives and simply forgets its parent. Not a blocker, but reported,
 *     because a lecture quietly losing its lesson is a consequence worth naming.
 *   - CASCADE: the child is destroyed with the parent. Not a blocker; cascade is only ever used
 *     here for a learner's own playback position, never for assessed work.
 */
class DependencyAnalyzer
{
    /** Readable names for what a blocking row actually represents to a person. */
    private const LABELS = [
        'lesson_completions' => 'learner progress records',
        'study_notes' => 'private study notes',
        'materials' => 'course materials',
        'video_lectures' => 'video lectures',
        'video_lecture_progress' => 'lecture viewing progress',
        'video_lecture_tracks' => 'captions and transcripts',
        'video_lecture_revisions' => 'retained lecture recordings',
        'material_revisions' => 'retained material files',
        'submissions' => 'assignment submissions',
        'submission_revisions' => 'retained submission versions',
        'assignments' => 'assignments',
        'assignment_extensions' => 'deadline extensions',
        'assessment_grade_changes' => 'grade history',
        'result_publications' => 'published results',
        'quizzes' => 'quizzes',
        'quiz_attempts' => 'quiz attempts',
        'quiz_assessment_changes' => 'quiz review history',
        'enrollments' => 'enrolments',
        'announcements' => 'announcements',
        'announcement_reads' => 'announcement read receipts',
        'course_access_changes' => 'access history',
        'lessons' => 'lessons',
    ];

    /**
     * @return array{
     *     blocked: bool,
     *     blockers: list<array{table: string, label: string, count: int}>,
     *     detaching: list<array{table: string, label: string, count: int}>,
     *     summary: string
     * }
     */
    public function for(string $table, int $id): array
    {
        $blockers = [];
        $detaching = [];

        foreach ($this->referencesTo($table) as $reference) {
            $count = DB::table($reference->child)->where($reference->column, $id)->count();
            if ($count === 0) {
                continue;
            }
            $entry = ['table' => $reference->child, 'label' => self::LABELS[$reference->child] ?? str_replace('_', ' ', $reference->child), 'count' => $count];

            if ($reference->rule === 'SET NULL') {
                $detaching[] = $entry;
            } elseif ($reference->rule !== 'CASCADE') {
                $blockers[] = $entry;
            }
        }

        usort($blockers, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'blocked' => $blockers !== [],
            'blockers' => $blockers,
            'detaching' => $detaching,
            'summary' => $this->summarize($blockers),
        ];
    }

    /** A sentence naming what is being preserved, for the person doing the deleting. */
    private function summarize(array $blockers): string
    {
        if ($blockers === []) {
            return 'Nothing else refers to this record, so it can be deleted permanently.';
        }

        $parts = array_map(fn ($blocker) => $blocker['count'].' '.$blocker['label'], array_slice($blockers, 0, 3));
        $listed = count($parts) === 1 ? $parts[0] : implode(', ', array_slice($parts, 0, -1)).' and '.end($parts);
        $extra = count($blockers) > 3 ? ', among others' : '';

        return 'Permanent deletion is unavailable because '.$listed.$extra.' depend on this record. It will be moved to Trash instead, so that history is preserved.';
    }

    /**
     * Foreign keys pointing at the given table, with the action the database would take.
     *
     * @return list<object{child: string, column: string, rule: string}>
     */
    private function referencesTo(string $table): array
    {
        return DB::select(
            'SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS `column`, r.DELETE_RULE AS rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME = ?',
            [DB::connection()->getDatabaseName(), $table],
        );
    }
}
