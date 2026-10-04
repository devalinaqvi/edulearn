<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Adds the business states the happy-path demo data does not reach, so that screens handling
 * drafts, archives, empty collections and recorded student history can be exercised without
 * hand-building records.
 *
 * Only states this schema actually supports are created. Assignment reference media is still
 * deliberately absent, because no attachment table exists and seeding it would misrepresent
 * the system.
 */
class DevelopmentStatesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic development states are limited to local and testing environments.');
        }

        $instructor = User::where('email', 'instructor@acumen.test')->firstOrFail();
        $student = User::where('email', 'student@acumen.test')->firstOrFail();

        $this->courseStates($instructor, $student);
        $this->recordedStudentHistory($instructor, $student);
        $this->announcementStates($instructor);
    }

    /** Draft, archived and content-free courses, alongside the published demo courses. */
    private function courseStates(User $instructor, User $student): void
    {
        $draft = Course::firstOrCreate(
            ['code' => 'EL-DRAFT'],
            ['instructor_id' => $instructor->id, 'title' => 'Unreleased: course in preparation', 'description' => 'A draft course. Learners cannot see it until it is published.', 'status' => 'draft'],
        );
        Lesson::firstOrCreate(['course_id' => $draft->id, 'position' => 1], ['title' => 'Draft lesson', 'body' => 'Content still being written.']);

        // An archived lesson, so Trash has something in it and the restore path has real input.
        $withdrawn = Lesson::firstOrCreate(
            ['course_id' => $draft->id, 'position' => 2],
            ['title' => 'Withdrawn lesson', 'body' => 'Removed from the course but kept on record.'],
        );
        if (! $withdrawn->isArchived()) {
            $withdrawn->forceFill(['status' => 'archived', 'archived_at' => now(), 'archived_by' => $instructor->id])->save();
        }

        // Archived with an enrolment retained: archival withdraws access without destroying records.
        $archived = Course::firstOrCreate(
            ['code' => 'EL-ARCHIVE'],
            ['instructor_id' => $instructor->id, 'title' => 'Retired: a previous course offering', 'description' => 'An archived course. Learning records are retained.', 'status' => 'archived'],
        );
        Lesson::firstOrCreate(['course_id' => $archived->id, 'position' => 1], ['title' => 'Retired lesson', 'body' => 'Kept as historical evidence.']);
        Enrollment::firstOrCreate(['course_id' => $archived->id, 'user_id' => $student->id]);

        // Published but entirely empty: every list on the course page renders its empty state.
        Course::firstOrCreate(
            ['code' => 'EL-EMPTY'],
            ['instructor_id' => $instructor->id, 'title' => 'Published course with no content yet', 'description' => 'Published before any lessons, assessments or materials were added.', 'status' => 'published'],
        );
    }

    /** A submitted assignment, a published grade, and a completed quiz attempt. */
    private function recordedStudentHistory(User $instructor, User $student): void
    {
        $course = Course::where('title', 'Web development foundations')->first();
        if (! $course) {
            return;
        }
        Enrollment::firstOrCreate(['course_id' => $course->id, 'user_id' => $student->id]);

        $assignment = Assignment::firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Graded example: the request-response cycle'],
            ['instructions' => 'Describe what happens between a browser request and the response, in your own words.', 'due_at' => now()->addDays(10)->setTime(23, 59), 'max_marks' => 20],
        );

        $body = 'The browser sends an HTTP request, a route maps it to a controller, the controller validates input and the server returns a response.';
        $submission = Submission::firstOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $student->id],
            ['body' => $body, 'submitted_at' => now()->subDay(), 'is_late' => false, 'status' => 'submitted', 'request_hash' => hash('sha256', $body)],
        );

        if ($submission->status !== 'graded') {
            $result = ['grade' => '17.00', 'feedback' => 'Clear and accurate. Say more about why HTTP being stateless matters for sessions.', 'rubric_scores' => null];
            $submission->update($result + ['grade_version' => 1, 'status' => 'graded', 'graded_by' => $instructor->id, 'graded_at' => now()]);
            DB::table('assessment_grade_changes')->insert(['submission_id' => $submission->id, 'actor_id' => $instructor->id, 'before' => json_encode(['grade' => null]), 'after' => json_encode($result), 'reason' => 'Initial marking of the seeded example.', 'created_at' => now()]);

            // Published, so the learner-visible released-result path has data behind it.
            DB::table('result_publications')->insert(['submission_id' => $submission->id, 'actor_id' => $instructor->id, 'grade_version' => 1, 'result' => json_encode($result), 'reason' => 'Seeded published result.', 'created_at' => now()]);
            $submission->update(['published_result' => $result, 'published_grade_version' => 1, 'result_published_at' => now()]);
        }

        $this->completedQuizAttempt($course, $instructor, $student);
    }

    private function completedQuizAttempt(Course $course, User $instructor, User $student): void
    {
        $questions = [
            ['type' => 'mcq', 'prompt' => 'Where must authorization always be enforced?', 'options' => ['In the browser', 'On the server', 'In the stylesheet', 'In the URL'], 'correct' => 1, 'points' => 5],
            ['type' => 'mcq', 'prompt' => 'What does validation check?', 'options' => ['That input has an acceptable shape and range', 'That the user is an administrator', 'That the page has loaded', 'That the database is empty'], 'correct' => 0, 'points' => 5],
        ];

        $quizId = DB::table('quizzes')->where('course_id', $course->id)->where('title', 'Example: keeping requests safe')->value('id');
        if (! $quizId) {
            $quizId = DB::table('quizzes')->insertGetId([
                'course_id' => $course->id, 'title' => 'Example: keeping requests safe',
                'instructions' => 'Two questions. One attempt. Save before the deadline.',
                'opens_at' => now()->subWeek(), 'closes_at' => now()->subDay(),
                'duration_minutes' => 20, 'questions' => json_encode($questions),
                'status' => 'published', 'version' => 1, 'published_by' => $instructor->id,
                'published_at' => now()->subWeek(), 'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
            ]);
        }

        // A quiz with no availability window: open from publication, with no closing deadline.
        if (! DB::table('quizzes')->where('course_id', $course->id)->where('title', 'Open practice: no deadline')->exists()) {
            DB::table('quizzes')->insert([
                'course_id' => $course->id, 'title' => 'Open practice: no deadline',
                'instructions' => 'Practise whenever you like. There is no closing date; each sitting is limited to its duration.',
                'opens_at' => null, 'closes_at' => null,
                'duration_minutes' => 15, 'questions' => json_encode($questions),
                'status' => 'published', 'version' => 1, 'published_by' => $instructor->id,
                'published_at' => now()->subDays(3), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
            ]);
        }

        // A closed quiz with a finalized attempt: the review-and-release screens have real input.
        if (! DB::table('quiz_attempts')->where('quiz_id', $quizId)->where('user_id', $student->id)->exists()) {
            DB::table('quiz_attempts')->insert([
                'quiz_id' => $quizId, 'user_id' => $student->id,
                'started_at' => now()->subDays(2), 'deadline_at' => now()->subDays(2)->addMinutes(20),
                'answers' => json_encode([1, 0]), 'version' => 1, 'score' => '10.00',
                'submitted_at' => now()->subDays(2)->addMinutes(12),
            ]);
        }
    }

    /** Published, scheduled and draft announcements, which the audience scope treats differently. */
    private function announcementStates(User $instructor): void
    {
        Announcement::firstOrCreate(
            ['course_id' => null, 'title' => 'Platform notice: scheduled maintenance'],
            ['author_id' => $instructor->id, 'body' => 'Already visible to everyone.', 'published_at' => now()->subHours(3)],
        );

        // published_at in the future: stored, but withheld until the time arrives.
        Announcement::firstOrCreate(
            ['course_id' => null, 'title' => 'Platform notice: next term enrolment opens soon'],
            ['author_id' => $instructor->id, 'body' => 'Scheduled ahead of time; not yet visible to anyone.', 'published_at' => now()->addWeek()],
        );

        // A draft has no publication time at all and is never an audience member.
        Announcement::firstOrCreate(
            ['course_id' => null, 'title' => 'Platform notice: unpublished draft'],
            ['author_id' => $instructor->id, 'body' => 'Written but never published.', 'published_at' => null],
        );
    }
}
