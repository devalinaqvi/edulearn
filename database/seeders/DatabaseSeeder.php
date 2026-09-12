<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo data is available only in local and testing environments.');
        }
        $password = 'Learning-demo-2026!';
        foreach ([['admin', 'Alex Morgan'], ['instructor', 'Jordan Lee'], ['student', 'Sam Taylor']] as [$role,$name]) {
            $user = User::firstOrCreate(['email' => $role.'@acumen.test'], ['name' => $name, 'password' => $password]);
            $user->forceFill(['role' => $role])->save();
        }
        $instructor = User::where('email', 'instructor@acumen.test')->firstOrFail();
        $student = User::where('email', 'student@acumen.test')->firstOrFail();
        $topics = [
            ['Designing for people', 'Explore the principles behind thoughtful, accessible digital experiences. Learn to turn real user needs into clear design decisions.', [
                ['Start with the person', 'Human-centered design starts by understanding the people who will use a product. Interviews reveal needs, goals, and constraints. Observation shows how people behave in context. A problem statement describes the user, their need, and why it matters. Avoid assuming that your own preferences represent all users.'],
                ['Make the essential visible', 'Visual hierarchy guides attention through differences in size, contrast, spacing, and position. Group related elements together. Use clear labels and consistent navigation. Accessible design includes readable text, sufficient contrast, keyboard access, and descriptive form labels.'],
                ['Learn through prototypes', 'A prototype is a simplified representation of a proposed experience. Low-fidelity prototypes let teams explore ideas quickly. Usability testing asks representative users to complete realistic tasks. Observe where they hesitate and revise the design based on evidence.']]],
            ['Web development foundations', 'Build a strong understanding of how the web works, from semantic HTML to secure server-side request handling.', [
                ['The request-response cycle', 'A browser sends an HTTP request to a server. A route maps the request to application logic. A controller validates input and coordinates work. The server returns a response containing HTML or structured data. HTTP is stateless; sessions let applications maintain login state securely.'],
                ['Structure with semantic HTML', 'Semantic HTML describes the meaning of content. Headings establish a logical outline. Labels connect text to form controls. Buttons trigger actions and links navigate. Choosing the right element improves accessibility and makes interfaces easier to understand.'],
                ['Keep data on the server safe', 'Validation checks whether input has an acceptable shape and range. Authorization determines whether a user may perform an operation. Authentication establishes who the user is. Always enforce authorization on the server, even when a button is hidden in the interface.']]],
            ['Making sense of data', 'Move from raw numbers to useful questions. Discover practical ways to interpret, summarize, and communicate data.', [
                ['Ask a useful question', 'Data analysis begins with a question. Define the population, the measurement, and the decision the analysis will inform. A sample is a subset of a population. Sampling bias occurs when the sample systematically excludes or overrepresents some groups.'],
                ['Describe the distribution', 'The mean is the sum of values divided by their count. The median is the middle value of sorted observations. Outliers can strongly affect the mean. The range is the maximum minus the minimum. Choose summaries that reflect the shape of the data.'],
                ['Correlation and causation', 'Correlation describes an association between variables. Correlation alone does not establish causation. Confounding variables may influence both measured variables. Experiments with suitable controls can provide stronger evidence about causal effects.']]],
        ];
        foreach ($topics as $index => [$title,$description,$lessons]) {
            $course = Course::firstOrCreate(['title' => $title], ['description' => $description, 'instructor_id' => $instructor->id, 'status' => 'published']);
            Enrollment::firstOrCreate(['course_id' => $course->id, 'user_id' => $student->id]);
            foreach ($lessons as $i => [$lessonTitle,$body]) {
                $lesson = Lesson::firstOrCreate(['course_id' => $course->id, 'position' => $i + 1], ['title' => $lessonTitle, 'body' => $body]);
                if ($index === 0 && $i === 0) {
                    LessonCompletion::firstOrCreate(['lesson_id' => $lesson->id, 'user_id' => $student->id]);
                }
            }
            Assignment::firstOrCreate(['course_id' => $course->id, 'title' => 'Apply what you have learned'], ['instructions' => 'Choose one concept from this course. Explain it in your own words and give a practical example in 150–300 words.', 'due_at' => now()->addDays(7)->setTime(23, 59), 'max_marks' => 100]);
            Announcement::firstOrCreate(['course_id' => $course->id, 'title' => 'Welcome to the course'], ['body' => 'Start with the first lesson and work at your own pace. Mark each lesson complete when you feel ready, then put your understanding into practice with the assignment.']);
            $path = 'materials/demo-'.$course->id.'.txt';
            Storage::disk('local')->put($path, implode("\n\n", array_column($lessons, 1)));
            Material::firstOrCreate(['course_id' => $course->id, 'path' => $path], ['title' => 'Course revision reader', 'original_name' => 'revision-reader.txt', 'format' => 'txt']);
        }
        $this->call(AssessmentDemoSeeder::class);
    }
}
