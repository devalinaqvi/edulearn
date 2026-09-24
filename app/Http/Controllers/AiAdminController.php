<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiConfigurationRequest;
use App\Models\AiConfiguration;
use App\Services\AiSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AiAdminController extends Controller
{
    public function index(Request $request, AiSettings $settings): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $configuration = $settings->current();
        $hasKey = (bool) $configuration->api_key;
        $safe = $configuration->toArray();
        $catalogues = [
            'openrouter' => Cache::get('ai.models.openrouter', []),
            'openai' => Cache::get('ai.models.openai', []),
        ];
        $refreshedAt = [
            'openrouter' => $settings->catalogRefreshedAt('openrouter'),
            'openai' => $settings->catalogRefreshedAt('openai'),
        ];
        $usage = DB::table('ai_usage_events')->orderByDesc('id')->paginate(30);

        return view('admin.ai', compact('safe', 'hasKey', 'catalogues', 'refreshedAt', 'usage'));
    }

    public function update(UpdateAiConfigurationRequest $request, AiSettings $settings): RedirectResponse
    {
        $data = $request->validated();
        if ($data['provider'] === 'openrouter') {
            try {
                $models = $settings->models('openrouter');
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
            }
            if (! isset($models[$data['model']])) {
                throw ValidationException::withMessages(['model' => 'Select a currently available zero-price OpenRouter model. Pull the catalogue to see today\'s list; models are withdrawn regularly.']);
            }
        }
        if ($data['provider'] === 'openai') {
            // Only constrain the choice when a catalogue could actually be pulled, so the very
            // first save (which is what stores the credential) is not a deadlock.
            $known = Cache::get('ai.models.openai', []);
            if ($known && ! isset($known[$data['model']])) {
                throw ValidationException::withMessages(['model' => 'Select a model from the pulled OpenAI catalogue, or pull it again.']);
            }
        }
        DB::transaction(function () use ($request, $settings, $data) {
            DB::table('lms_write_locks')->where('id', 1)->lockForUpdate()->first();
            abort_unless($request->user()->fresh()->is_active && $request->user()->fresh()->role === 'admin', 403);
            $current = $settings->current();
            abort_unless((int) $data['version'] === $current->version, 409, 'AI settings changed. Reload before saving.');
            $key = $request->boolean('remove_key') ? null : ($data['api_key'] ?? ($current->provider === $data['provider'] ? $current->api_key : null));
            if ($request->boolean('enabled') && $data['provider'] !== 'mock' && ! $key) {
                throw ValidationException::withMessages(['api_key' => 'Configure a server-side API credential before enabling this provider.']);
            }
            $values = collect($data)->except(['remove_key', 'allow_paid', 'version', 'api_key', 'require_zero_retention'])->all() + ['api_key' => $key, 'require_zero_retention' => $request->boolean('require_zero_retention'), 'version' => $current->version + 1];
            $configuration = AiConfiguration::firstOrNew(['id' => 1]);
            $configuration->forceFill(['id' => 1])->fill($values)->save();
            DB::table('account_activity')->insert(['user_id' => $request->user()->id, 'actor_id' => $request->user()->id, 'event' => 'ai_settings_updated', 'details' => json_encode(['provider' => $data['provider'], 'enabled' => $request->boolean('enabled'), 'require_zero_retention' => $request->boolean('require_zero_retention'), 'version' => $current->version + 1]), 'created_at' => now()]);
        });

        return back()->with('status', 'AI configuration saved. Queued requests using an older configuration must be regenerated.');
    }

    public function refresh(Request $request, AiSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $provider = $request->validate(['provider' => 'required|in:openrouter,openai'])['provider'];
        try {
            $models = $settings->models($provider, true);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', $models
            ? 'Pulled '.count($models).' '.($provider === 'openrouter' ? 'zero-price OpenRouter' : 'OpenAI').' models.'
            : 'The provider returned no selectable models.');
    }

    public function test(Request $request, AiSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $configuration = $settings->current();
        if ($configuration->provider === 'mock') {
            return back()->with('status', 'Development mock selected. No external connection was tested.');
        }
        try {
            $response = Http::withToken($configuration->api_key ?? '')->acceptJson()->connectTimeout(5)->timeout(15)->get($configuration->provider === 'openrouter' ? 'https://openrouter.ai/api/v1/key' : 'https://api.openai.com/v1/models');
            $success = $response->successful();
        } catch (\Throwable) {
            $success = false;
        }
        DB::table('ai_usage_events')->insert(['provider' => $configuration->provider, 'model' => $configuration->model, 'status' => $success ? 'connection_ok' : 'connection_failed', 'input_chars' => 0, 'created_at' => now()]);

        return $success ? back()->with('status', 'Credentials authenticated. No course content was sent and no inference was requested.') : back()->withErrors(['provider' => 'Connection failed. Check credentials and provider availability.']);
    }
}
