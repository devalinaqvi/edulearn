@extends('layouts.app')
@section('title', 'AI administration')
@section('content')
@php
    $current = old('model', $safe['model']);
    $labels = ['openrouter' => 'OpenRouter — free (zero price)', 'openai' => 'OpenAI — paid'];
    // Keep the saved model selectable even after a provider withdraws it, so the form round-trips
    // instead of silently losing the configured value.
    $known = array_merge($catalogues['openrouter'], $catalogues['openai']);
@endphp
<h1>AI administration</h1>
<p class="muted">OpenRouter requests permit only zero-price models, deny data collection, require zero data retention, and disable fallback. Availability may be limited by these restrictions.</p>
<form class="panel" method="post" action="{{ route('admin.ai.update') }}">@csrf @method('put')<input type="hidden" name="version" value="{{ $safe['version'] }}">
<label>AI features<select name="enabled"><option value="1" @selected($safe['enabled'])>Enabled</option><option value="0" @selected(!$safe['enabled'])>Disabled</option></select></label>
<label>Provider<select name="provider" data-ai-provider>@foreach(['mock' => 'Development mock (no AI service)', 'openrouter' => 'OpenRouter (free models only)', 'openai' => 'OpenAI (explicit paid use)'] as $value => $label)<option value="{{ $value }}" @selected(old('provider', $safe['provider']) === $value)>{{ $label }}</option>@endforeach</select></label>

<label>Model<select name="model" data-ai-model>
<option value="" @selected(!$current)>— Not applicable (development mock) —</option>
@foreach(['openrouter', 'openai'] as $provider)
<optgroup label="{{ $labels[$provider] }}" data-provider="{{ $provider }}">
@forelse($catalogues[$provider] as $id => $name)<option value="{{ $id }}" data-provider="{{ $provider }}" @selected($current === $id)>{{ $name }}@if($name !== $id) ({{ $id }})@endif</option>
@empty<option value="" disabled>No catalogue pulled yet — use the pull button below</option>@endforelse
</optgroup>
@endforeach
@if($current && ! isset($known[$current]))<optgroup label="Currently saved (not in any pulled catalogue)" data-provider="{{ $safe['provider'] }}"><option value="{{ $current }}" data-provider="{{ $safe['provider'] }}" selected>{{ $current }}</option></optgroup>@endif
</select></label>
<p class="muted">
OpenRouter catalogue pulled: {{ $refreshedAt['openrouter'] ?? 'never' }} ({{ count($catalogues['openrouter']) }} models).
OpenAI catalogue pulled: {{ $refreshedAt['openai'] ?? 'never' }} ({{ count($catalogues['openai']) }} models).
Pull a catalogue before selecting a model — providers withdraw models regularly, and a withdrawn model is rejected on save rather than silently replaced with a paid one.
</p>

<label>Replace API credential<input type="password" name="api_key" autocomplete="new-password" maxlength="4096"></label>
<p class="muted">{{ $hasKey ? 'A credential is configured. Leave blank to retain it for the same provider.' : 'No credential configured.' }}</p>
<label><input type="checkbox" name="remove_key" value="1"> Remove stored credential</label>
<input type="hidden" name="require_zero_retention" value="0"><label><input type="checkbox" name="require_zero_retention" value="1" @checked(old('require_zero_retention', $safe['require_zero_retention'] ?? true))> Require zero data retention from the provider</label>
<p class="muted"><strong>Leave this on unless you accept the consequence.</strong> With it on, OpenRouter will only route to endpoints that retain nothing. At the time of writing <em>no</em> zero-price OpenRouter model offers such an endpoint, so free models will always fail with a data-policy error. Turning it off lets free models work, but the provider may retain submitted lesson and material text under its own policy. Data collection for training is refused either way, and no paid fallback is ever attempted.</p>
<label>Requests per user per day (UTC)<input type="number" name="daily_limit" min="1" max="100" value="{{ $safe['daily_limit'] }}" required></label>
<label>Maximum input characters<input type="number" name="max_input_chars" min="1000" max="40000" value="{{ $safe['max_input_chars'] }}" required></label>
<label>Maximum output tokens<input type="number" name="max_output_tokens" min="256" max="4000" value="{{ $safe['max_output_tokens'] }}" required></label>
<label><input type="checkbox" name="allow_paid" value="1" @checked(old('allow_paid'))> I explicitly approve paid OpenAI usage if OpenAI is enabled.</label>
<button class="button">Save AI settings</button></form>

<div class="two-col">
<form class="panel" method="post" action="{{ route('admin.ai.models') }}">@csrf<input type="hidden" name="provider" value="openrouter"><button class="button secondary">Pull OpenRouter free models</button><p class="muted">Public catalogue; no credential needed.</p></form>
<form class="panel" method="post" action="{{ route('admin.ai.models') }}">@csrf<input type="hidden" name="provider" value="openai"><button class="button secondary">Pull OpenAI models</button><p class="muted">Requires a saved OpenAI credential. Save the key first, then pull.</p></form>
</div>
<form class="panel" method="post" action="{{ route('admin.ai.test') }}">@csrf<button class="button secondary">Test saved credentials</button><p class="muted">Checks authentication without sending course content or requesting inference.</p></form>

<section class="panel"><h2>Recent AI activity</h2>@forelse($usage as $event)<p>{{ $event->provider }} · {{ $event->model }} · {{ $event->status }} · {{ $event->input_chars }} input characters · {{ $event->created_at }} UTC @if($event->detail ?? null)<br><small class="muted">{{ $event->detail }}</small>@endif</p>@empty<p>No recorded requests yet.</p>@endforelse{{ $usage->links() }}</section>
@endsection
