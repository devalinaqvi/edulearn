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
        return ['enabled' => 1, 'provider' => 'openrouter', 'model' => 'vendor/free-model:free', 'api_key' => 'test-private-key', 'daily_limit' => 3, 'max_input_chars' => 12000, 'max_output_tokens' => 1000, 'version' => 0];
    }

    public function test_admin_configuration_encrypts_credentials_and_rejects_paid_models_and_stale_updates(): void
    {
        $this->catalog();
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('admin.ai'))->assertForbidden();
        $this->put(route('admin.ai.update'), $this->payload())->assertForbidden();
        $this->actingAs($admin)->post(route('admin.ai.models'))->assertRedirect();
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
}
