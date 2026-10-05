<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\StudyNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A right-to-erasure request removes the person, not the academic record.
 *
 * The tests assert both halves deliberately. Erasing too little fails the request; erasing too
 * much destroys the evidence an appeal or a degree verification depends on, which the Article
 * 17(3) exemptions exist to protect.
 */
class AccountErasureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $learner;

    private Course $course;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->teacher = User::factory()->create(['role' => 'instructor']);
        $this->learner = User::factory()->create(['role' => 'student', 'name' => 'Jamie Learner', 'email' => 'jamie@example.test']);
        $this->course = Course::create(['code' => 'EL-GDPR', 'instructor_id' => $this->teacher->id, 'title' => 'Erasure course', 'description' => 'Online', 'status' => 'published']);
        $this->course->enrollments()->create(['user_id' => $this->learner->id]);
        $this->assignment = $this->course->assignments()->create(['title' => 'Essay', 'instructions' => 'Write it', 'due_at' => now()->addWeek(), 'max_marks' => 20]);
    }

    private function buildHistory(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1]);
        $this->actingAs($this->learner)->post(route('lessons.complete', $lesson), ['completed' => '1'])->assertRedirect();
        $this->post(route('assignments.submit', $this->assignment), ['body' => 'The essay I submitted'])->assertRedirect();
        StudyNote::create([
            'user_id' => $this->learner->id, 'course_id' => $this->course->id, 'lesson_id' => $lesson->id,
            'title' => 'My revision notes', 'source_title' => 'Lesson', 'status' => 'completed',
            'content' => 'Private notes', 'request_key' => 'test-'.$lesson->id, 'provider' => 'mock',
        ]);
    }

    private function erase(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.users.erase', $this->learner), array_merge([
            'confirm_email' => $this->learner->email,
        ], $overrides));
    }

    public function test_identifying_details_are_overwritten(): void
    {
        $this->erase()->assertRedirect();

        $erased = $this->learner->fresh();
        $this->assertSame('Removed account '.$erased->id, $erased->name);
        $this->assertSame('erased-'.$erased->id.'@erased.invalid', $erased->email);
        $this->assertNotNull($erased->erased_at);
        $this->assertFalse((bool) $erased->is_active);
        $this->assertNull($erased->remember_token);
        $this->assertNull($erased->last_login_at);

        // Nothing of the original identity survives anywhere on the row.
        $this->assertStringNotContainsString('Jamie', json_encode($erased->getAttributes()));
        $this->assertStringNotContainsString('jamie@example.test', json_encode($erased->getAttributes()));
    }

    public function test_the_academic_record_is_retained_and_still_attached_to_the_account(): void
    {
        $this->buildHistory();
        $this->erase()->assertRedirect();

        $this->assertDatabaseHas('enrollments', ['course_id' => $this->course->id, 'user_id' => $this->learner->id]);
        $this->assertDatabaseHas('lesson_completions', ['user_id' => $this->learner->id]);
        // The submitted work itself is kept: it is the evidence an appeal would rest on.
        $this->assertDatabaseHas('submissions', ['user_id' => $this->learner->id, 'body' => 'The essay I submitted']);
    }

    public function test_personal_material_with_no_assessment_value_is_deleted(): void
    {
        $this->buildHistory();
        $this->assertSame(1, StudyNote::where('user_id', $this->learner->id)->count());

        $this->erase()->assertRedirect();

        $this->assertSame(0, StudyNote::where('user_id', $this->learner->id)->count());
        $this->assertSame(0, DB::table('announcement_reads')->where('user_id', $this->learner->id)->count());
        $this->assertSame(0, DB::table('video_lecture_progress')->where('user_id', $this->learner->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->learner->id)->count());
    }

    public function test_the_erased_account_can_no_longer_sign_in(): void
    {
        $original = $this->learner->email;
        $this->erase()->assertRedirect();

        // Stop acting as the administrator who performed the erasure.
        $this->post(route('logout'))->assertRedirect('/login');
        $this->assertGuest();

        // The address no longer belongs to anyone, so the generic failure path is taken.
        $this->post('/login', ['email' => $original, 'password' => 'password']);
        $this->assertGuest();

        // Nor can the tombstone address be used, whatever is tried against it.
        $this->post('/login', ['email' => $this->learner->fresh()->email, 'password' => 'password']);
        $this->assertGuest();

        // The stored hash matches nothing the person knew.
        $this->assertFalse(Hash::check('password', $this->learner->fresh()->password));
    }

    public function test_the_erasure_is_evidenced_without_naming_the_subject(): void
    {
        $this->buildHistory();
        $this->erase()->assertRedirect();

        $entry = DB::table('account_activity')->where('event', 'erased')->sole();
        $this->assertSame($this->admin->id, (int) $entry->actor_id);
        $this->assertSame($this->learner->id, (int) $entry->user_id);

        $details = json_decode($entry->details, true);
        $this->assertSame(1, $details['removed']['study_notes']);
        $this->assertSame(1, $details['retained']['submissions']);
        // Counts only: the record of the erasure must not reintroduce what was erased.
        $this->assertStringNotContainsString('Jamie', $entry->details);
        $this->assertStringNotContainsString('jamie@example.test', $entry->details);
    }

    public function test_erasure_requires_the_email_typed_exactly(): void
    {
        $this->erase(['confirm_email' => 'wrong@example.test'])->assertSessionHasErrors('confirm_email');
        $this->erase(['confirm_email' => ''])->assertSessionHasErrors('confirm_email');

        $this->assertNull($this->learner->fresh()->erased_at);
        $this->assertSame('Jamie Learner', $this->learner->fresh()->name);
    }

    public function test_only_an_administrator_may_erase_an_account(): void
    {
        foreach ([$this->teacher, $this->learner] as $actor) {
            $this->actingAs($actor)->post(route('admin.users.erase', $this->learner), ['confirm_email' => $this->learner->email])
                ->assertForbidden();
        }

        $this->assertNull($this->learner->fresh()->erased_at);
    }

    public function test_an_administrator_cannot_erase_their_own_account(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.erase', $this->admin), ['confirm_email' => $this->admin->email])
            ->assertStatus(422);

        $this->assertNull($this->admin->fresh()->erased_at);
    }

    public function test_the_last_active_administrator_cannot_be_erased(): void
    {
        $other = User::factory()->create(['role' => 'admin']);

        // With two administrators this is permitted.
        $this->actingAs($this->admin)->post(route('admin.users.erase', $other), ['confirm_email' => $other->email])->assertRedirect();

        // The remaining one cannot erase itself, and no other administrator exists to do it.
        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
        $this->actingAs($this->admin)->post(route('admin.users.erase', $this->admin), ['confirm_email' => $this->admin->email])
            ->assertStatus(422);
    }

    public function test_erasing_twice_is_refused_rather_than_repeated(): void
    {
        $this->erase()->assertRedirect();

        // The second attempt cannot even be confirmed: the address it would need no longer exists.
        $this->erase()->assertSessionHasErrors('confirm_email');
        $this->actingAs($this->admin)->post(route('admin.users.erase', $this->learner), [
            'confirm_email' => $this->learner->fresh()->email,
        ])->assertSessionHasErrors('conflict');

        $this->assertSame(1, DB::table('account_activity')->where('event', 'erased')->count());
    }

    public function test_the_administration_screen_offers_and_then_reports_erasure(): void
    {
        $this->actingAs($this->admin)->get(route('admin'))->assertOk()
            ->assertSee('Erase personal data')
            ->assertSee('jamie@example.test');

        $this->erase()->assertRedirect();

        $this->get(route('admin'))->assertOk()
            ->assertSee('Personal data for this account was erased')
            ->assertDontSee('jamie@example.test');
    }

    public function test_an_erased_instructor_no_longer_names_their_own_audit_entries(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Lesson', 'body' => 'Body', 'position' => 1])->refresh();
        $this->actingAs($this->teacher)->post(route('lessons.archive', $lesson), ['version' => $lesson->version, 'action' => 'archive'])->assertRedirect();
        $this->assertDatabaseHas('content_lifecycle_changes', ['actor_id' => $this->teacher->id]);

        $this->actingAs($this->admin)->post(route('admin.users.erase', $this->teacher), ['confirm_email' => $this->teacher->email])->assertRedirect();

        // The entry survives, which keeps the trail complete, but no longer names a person.
        $this->assertDatabaseHas('content_lifecycle_changes', ['actor_id' => $this->teacher->id]);
        $this->assertSame('Removed account '.$this->teacher->id, $this->teacher->fresh()->name);
    }
}
