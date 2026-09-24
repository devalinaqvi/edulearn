<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\User;
use App\Services\NotesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): void
    {
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'vendor/free-model:free', 'name' => 'Free model', 'pricing' => ['prompt' => '0', 'completion' => '0', 'request' => '0']],
            ['id' => 'vendor/paid-model', 'name' => 'Paid model', 'pricing' => ['prompt' => '0.001', 'completion' => '0.002']],
        ]])]);
    }

    private function payload(): array
    {
        return ['enabled' => 1, 'provider' => 'openrouter', 'model' => 'vendor/free-model:free', 'api_key' => 'test-private-key', 'require_zero_retention' => 1, 'daily_limit' => 3, 'max_input_chars' => 12000, 'max_output_tokens' => 1000, 'version' => 0];
    }

    public function test_admin_configuration_encrypts_credentials_and_rejects_paid_models_and_stale_updates(): void
    {
        $this->catalog();
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('admin.ai'))->assertForbidden();
        $this->put(route('admin.ai.update'), $this->payload())->assertForbidden();
        $this->actingAs($admin)->post(route('admin.ai.models'), ['provider' => 'openrouter'])->assertRedirect();
        $this->get(route('admin.ai'))->assertOk()->assertSee('Free model')->assertDontSee('Paid model');
        $this->put(route('admin.ai.update'), array_replace($this->payload(), ['model' => 'vendor/paid-model']))->assertSessionHasErrors('model')->assertSessionMissing('_old_input.api_key');
        $this->put(route('admin.ai.update'), $this->payload())->assertRedirect();
        $this->assertStringNotContainsString('test-private-key', DB::table('ai_configurations')->value('api_key'));
        $this->assertSame('test-private-key', AiConfiguration::findOrFail(1)->api_key);
        $this->get(route('admin.ai'))->assertOk()->assertDontSee('test-private-key');
        $this->put(route('admin.ai.update'), $this->payload())->assertConflict();
        $this->put(route('admin.ai.update'), array_replace($this->payload(), ['version' => 1, 'provider' => 'openai', 'model' => 'test-model']))->assertSessionHasErrors('allow_paid');
    }

    public function test_openrouter_generation_uses_zero_price_privacy_constraints_and_logs_no_source_or_key(): void
    {
        $this->catalog();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.ai.update'), $this->payload())->assertRedirect();
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Summary: Atomic transactions. [Source]']]]])]);
        $result = (new NotesProvider)->generate('PRIVATE SOURCE TEXT', 'openrouter');
        $this->assertStringContainsString('Atomic transactions', $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions' && $request['model'] === 'vendor/free-model:free' && $request['provider']['max_price']['prompt'] === 0 && $request['provider']['max_price']['completion'] === 0 && $request['provider']['allow_fallbacks'] === false && $request['provider']['data_collection'] === 'deny' && $request['provider']['zdr'] === true);
        $events = json_encode(DB::table('ai_usage_events')->get());
        $this->assertStringNotContainsString('PRIVATE SOURCE TEXT', $events);
        $this->assertStringNotContainsString('test-private-key', $events);
        $this->assertDatabaseHas('ai_usage_events', ['status' => 'completed', 'provider' => 'openrouter']);
    }

    public function test_provider_failure_has_no_paid_fallback_and_disabled_ai_makes_no_call(): void
    {
        $this->catalog();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.ai.update'), $this->payload())->assertRedirect();
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response(['error' => 'PRIVATE PROVIDER DETAIL'], 429)]);
        try {
            (new NotesProvider)->generate('Source', 'openrouter');
            $this->fail('Generation must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI generation failed. No paid fallback was attempted.', $exception->getMessage());
        }
        $this->assertDatabaseHas('ai_usage_events', ['status' => 'failed']);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'chat/completions')));
        AiConfiguration::findOrFail(1)->update(['enabled' => false]);
        Http::fake();
        try {
            (new NotesProvider)->generate('Source', 'openrouter');
            $this->fail('Disabled AI must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_model_dropdowns_are_pulled_per_provider_and_openai_needs_a_saved_credential(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'vendor/free-model:free', 'name' => 'Free model', 'pricing' => ['prompt' => '0', 'completion' => '0']],
                ['id' => 'vendor/paid-model', 'name' => 'Paid model', 'pricing' => ['prompt' => '0.001', 'completion' => '0.002']],
            ]]),
            'api.openai.com/v1/models' => Http::response(['data' => [
                ['id' => 'gpt-4.1-mini'], ['id' => 'gpt-5'],
                ['id' => 'text-embedding-3-small'], ['id' => 'whisper-1'], ['id' => 'dall-e-3'],
            ]]),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        // OpenAI cannot be pulled before a credential exists.
        $this->actingAs($admin)->post(route('admin.ai.models'), ['provider' => 'openai'])->assertSessionHasErrors('provider');
        $this->flushSession();

        // OpenRouter is a public catalogue and lists only zero-price models.
        $this->actingAs($admin)->post(route('admin.ai.models'), ['provider' => 'openrouter'])->assertRedirect();
        $this->get(route('admin.ai'))->assertOk()
            ->assertSee('Free model')->assertDontSee('Paid model')
            ->assertSee('data-ai-model', false)->assertSee('<optgroup', false);

        // Save a credential, then the OpenAI catalogue pulls and excludes non-text models.
        $this->put(route('admin.ai.update'), $this->payload())->assertRedirect();
        $this->post(route('admin.ai.models'), ['provider' => 'openai'])->assertRedirect()->assertSessionHas('status');
        $this->get(route('admin.ai'))->assertOk()
            ->assertSee('gpt-4.1-mini')->assertSee('gpt-5')
            ->assertDontSee('text-embedding-3-small')->assertDontSee('whisper-1')->assertDontSee('dall-e-3');

        $this->assertSame('openrouter', $this->refresh_provider());
    }

    private function refresh_provider(): string
    {
        return AiConfiguration::findOrFail(1)->provider;
    }

    public function test_a_withdrawn_openrouter_model_is_rejected_and_never_silently_replaced(): void
    {
        Http::preventStrayRequests();
        // A sequence, because Http::fake() appends stubs rather than replacing them.
        Http::fakeSequence('openrouter.ai/api/v1/models')
            ->push(['data' => [['id' => 'vendor/free-model:free', 'name' => 'Free model', 'pricing' => ['prompt' => '0', 'completion' => '0']]]])
            ->push(['data' => [['id' => 'vendor/other-paid', 'name' => 'Other paid', 'pricing' => ['prompt' => '0.5', 'completion' => '0.5']]]]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.ai.models'), ['provider' => 'openrouter'])->assertRedirect();
        $this->put(route('admin.ai.update'), $this->payload())->assertRedirect();
        $this->assertSame('vendor/free-model:free', AiConfiguration::findOrFail(1)->model);

        // The provider withdraws the model; the saved configuration must not follow a paid one.
        $this->post(route('admin.ai.models'), ['provider' => 'openrouter'])->assertRedirect();
        $this->put(route('admin.ai.update'), array_replace($this->payload(), ['version' => 1]))
            ->assertSessionHasErrors('model');
        $this->assertSame('vendor/free-model:free', AiConfiguration::findOrFail(1)->model);

        // The withdrawn-but-saved model still renders so the form round-trips.
        $this->get(route('admin.ai'))->assertOk()->assertSee('not in any pulled catalogue');
    }

    public function test_openai_model_must_come_from_the_pulled_catalogue_once_one_exists(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => []]),
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4.1-mini']]]),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $paid = ['enabled' => 1, 'provider' => 'openai', 'api_key' => 'openai-key', 'allow_paid' => 1,
            'daily_limit' => 3, 'max_input_chars' => 12000, 'max_output_tokens' => 1000];

        $this->actingAs($admin)->put(route('admin.ai.update'), $paid + ['model' => 'gpt-4.1-mini', 'version' => 0])->assertRedirect();
        $this->post(route('admin.ai.models'), ['provider' => 'openai'])->assertRedirect();
        $this->put(route('admin.ai.update'), $paid + ['model' => 'gpt-9-imaginary', 'version' => 1])->assertSessionHasErrors('model');
        $this->assertSame('gpt-4.1-mini', AiConfiguration::findOrFail(1)->model);
    }
}
