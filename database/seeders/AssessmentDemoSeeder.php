<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssessmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic assessments are limited to local/testing.');
        }
        DB::transaction(function () {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            $course = Course::where('title', 'Designing for people')->firstOrFail();
            Assignment::firstOrCreate(['course_id' => $course->id, 'title' => 'Demonstration: evidence and explanation'], ['instructions' => 'Synthetic practice assessment. Explain a concept from the first lesson, then give a concrete example. This is an online practice assessment.', 'due_at' => now()->addWeek()->setTime(23, 59), 'max_marks' => 20, 'rubric' => [['label' => 'Accurate explanation', 'max_marks' => 12], ['label' => 'Relevant example', 'max_marks' => 8]], 'rubric_version' => 1]);
            if (! DB::table('quizzes')->where('course_id', $course->id)->where('title', 'Demonstration: learning readiness')->exists()) {
                DB::table('quizzes')->insert(['course_id' => $course->id, 'title' => 'Demonstration: learning readiness', 'instructions' => 'Synthetic practice only. Save your answers regularly. One attempt; the closing time may shorten your time limit.', 'opens_at' => now()->subDay(), 'closes_at' => now()->addMonth(), 'duration_minutes' => 15, 'questions' => json_encode([['prompt' => 'What should you do before the quiz deadline?', 'options' => ['Save your answers', 'Close the browser without saving', 'Wait for the deadline to pass', 'Assume opening the page earns marks'], 'correct' => 0, 'points' => 5], ['prompt' => 'What does a marking rubric explain?', 'options' => ['Campus directions', 'Criteria used to assess work', 'Your account password', 'The room booking schedule'], 'correct' => 1, 'points' => 5]]), 'status' => 'published', 'version' => 1, 'published_by' => $course->instructor_id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
