<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authored HTML is cleaned on the way in, so what is stored is already safe to render.
 *
 * Every assertion here goes through the HTTP layer rather than calling the sanitizer directly
 * where it can, because the property that matters is "this cannot be stored", not "this function
 * returns a clean string".
 */
class RichTextLessonTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::create(['code' => 'EL-RTE', 'instructor_id' => $this->teacher->id, 'title' => 'Rich text course', 'description' => 'Online', 'status' => 'published']);
    }

    private function writeLesson(string $body, string $format = 'html'): Lesson
    {
        $this->actingAs($this->teacher)->post(route('lessons.store', $this->course), [
            'title' => 'Lesson', 'body' => $body, 'position' => 1, 'body_format' => $format,
        ])->assertRedirect();

        return Lesson::where('course_id', $this->course->id)->latest('id')->firstOrFail();
    }

    public function test_supported_formatting_survives_a_round_trip(): void
    {
        $lesson = $this->writeLesson('<h2>Heading</h2><p>A <strong>bold</strong> and <em>italic</em> point.</p><ul><li>One</li><li>Two</li></ul>');

        $this->assertStringContainsString('<h2>Heading</h2>', $lesson->body);
        $this->assertStringContainsString('<strong>bold</strong>', $lesson->body);
        $this->assertStringContainsString('<li>One</li>', $lesson->body);
        $this->assertSame('html', $lesson->body_format);
    }

    public function test_a_script_tag_never_reaches_storage(): void
    {
        $lesson = $this->writeLesson('<p>Before</p><script>alert(document.cookie)</script><p>After</p>');

        $this->assertStringNotContainsString('<script', $lesson->body);
        $this->assertStringNotContainsString('alert(', $lesson->body);
        $this->assertStringContainsString('Before', $lesson->body);
    }

    public function test_event_handler_attributes_are_stripped(): void
    {
        $lesson = $this->writeLesson('<p onmouseover="steal()">Text</p><img src=x onerror="alert(1)">');

        $this->assertStringNotContainsString('onmouseover', $lesson->body);
        $this->assertStringNotContainsString('onerror', $lesson->body);
        $this->assertStringNotContainsString('alert', $lesson->body);
    }

    public function test_a_javascript_url_is_refused_while_a_real_link_is_kept(): void
    {
        $lesson = $this->writeLesson('<p><a href="javascript:alert(1)">bad</a> and <a href="https://example.org/guide">good</a></p>');

        $this->assertStringNotContainsString('javascript:', $lesson->body);
        $this->assertStringContainsString('https://example.org/guide', $lesson->body);
        // Outbound links must not hand the opener to another page.
        $this->assertStringContainsString('noopener', $lesson->body);
    }

    public function test_styles_frames_and_forms_are_removed(): void
    {
        $lesson = $this->writeLesson('<p>Keep</p><style>body{display:none}</style><iframe src="https://evil.test"></iframe><form action="/steal"><input name="password"></form>');

        foreach (['<style', '<iframe', '<form', '<input'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $lesson->body);
        }
        $this->assertStringContainsString('Keep', $lesson->body);
    }

    public function test_content_that_is_only_markup_is_rejected_rather_than_stored_empty(): void
    {
        $this->actingAs($this->teacher)->post(route('lessons.store', $this->course), [
            'title' => 'Lesson', 'body' => '<script>alert(1)</script>', 'position' => 1, 'body_format' => 'html',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, Lesson::where('course_id', $this->course->id)->count());
    }

    public function test_a_stored_lesson_renders_its_markup_while_legacy_text_stays_escaped(): void
    {
        $rich = $this->writeLesson('<p>Rendered <strong>markup</strong></p>');
        $this->assertTrue($rich->isRichText());

        $plain = $this->writeLesson("Line one\n\nLine two <not a tag>", 'text');
        $this->assertFalse($plain->isRichText());

        $response = $this->get(route('courses.show', $this->course))->assertOk();
        $response->assertSee('<strong>markup</strong>', false);
        // The legacy body is escaped, so its angle brackets are shown rather than interpreted.
        $response->assertSee('&lt;not a tag&gt;', false);
    }

    public function test_an_editable_lesson_still_submits_without_scripting(): void
    {
        $this->writeLesson('<p>Content</p>');

        // The textarea is the form value the server reads; the editor only enhances it.
        $this->get(route('courses.show', $this->course))->assertOk()
            ->assertSee('data-editor-source', false)
            ->assertSee('name="body_format"', false)
            ->assertSee('<textarea', false);
    }

    public function test_ai_sources_receive_prose_rather_than_markup(): void
    {
        $lesson = $this->writeLesson('<h2>Title</h2><p>First point.</p><ul><li>Second point.</li></ul>');

        $plain = $lesson->plainBody();
        $this->assertStringNotContainsString('<', $plain);
        $this->assertStringContainsString('First point.', $plain);
        $this->assertStringContainsString('Second point.', $plain);
    }

    public function test_the_sanitizer_reports_emptiness_for_content_with_no_substance(): void
    {
        $this->assertTrue(RichText::isEmpty('<p>   </p>'));
        $this->assertTrue(RichText::isEmpty('<script>x()</script>'));
        $this->assertFalse(RichText::isEmpty('<p>Something</p>'));
    }

    public function test_both_the_create_and_edit_forms_offer_the_editor(): void
    {
        $this->writeLesson('<p>Existing lesson</p>');

        $page = $this->get(route('courses.show', $this->course))->assertOk();

        // One editor for the existing lesson, one for the "add a lesson" form. The create form
        // was previously a bare textarea, so a new lesson could only ever be plain text.
        $this->assertSame(2, substr_count($page->getContent(), 'data-editor-toolbar'));
        $this->assertSame(2, substr_count($page->getContent(), 'data-editor-format'));
        $this->assertStringContainsString('id="new-lesson-body"', $page->getContent());
    }

    public function test_a_lesson_created_through_the_editor_is_stored_as_sanitized_html(): void
    {
        // What the editor posts for a new lesson: markup plus the format flag it sets.
        $this->actingAs($this->teacher)->post(route('lessons.store', $this->course), [
            'title' => 'Authored new', 'position' => 1, 'body_format' => 'html',
            'body' => '<h2>Intro</h2><p>Body <strong>text</strong></p><script>alert(1)</script>',
        ])->assertRedirect();

        $lesson = Lesson::where('course_id', $this->course->id)->sole();
        $this->assertSame('html', $lesson->body_format);
        $this->assertStringContainsString('<h2>Intro</h2>', $lesson->body);
        $this->assertStringNotContainsString('alert', $lesson->body);
    }

    public function test_only_course_staff_may_author_lesson_content(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->course->enrollments()->create(['user_id' => $student->id]);

        $this->actingAs($student)->post(route('lessons.store', $this->course), [
            'title' => 'Injected', 'body' => '<p>Hi</p>', 'position' => 1, 'body_format' => 'html',
        ])->assertForbidden();

        $this->assertSame(0, Lesson::where('course_id', $this->course->id)->count());
    }
}
