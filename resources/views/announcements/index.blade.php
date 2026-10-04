@extends('layouts.app')
@section('title', 'Announcements')
@section('content')
<div class="page-heading"><div><h1>Announcements</h1><p class="muted">Platform updates and messages for your courses.</p></div></div>
@if(auth()->user()->role === 'admin')<details class="panel"><summary>Publish a platform announcement</summary><form method="post" action="{{ route('announcements.platform') }}" data-confirm="Publish this announcement to every active account?">@csrf<label>Title<input name="title" required maxlength="160" value="{{ old('title') }}"></label><label>Message<textarea name="body" required maxlength="10000" rows="5">{{ old('body') }}</textarea></label><fieldset class="choice-group"><legend>When should this appear?</legend>
<label><input type="radio" name="state" value="now" checked> Publish now</label>
<label><input type="radio" name="state" value="schedule"> Schedule for later</label>
<label><input type="radio" name="state" value="draft"> Save as a draft</label>
</fieldset>
<label>Publication time (@tz)<input type="datetime-local" name="publish_at" value="{{ old('publish_at') }}"><small class="muted">Used only when scheduling. The server releases it at this time; nothing needs to stay open.</small></label><button class="button">Save announcement</button></form></details>@endif

@if($pending->isNotEmpty())
<div class="section-heading"><h2>Not yet published</h2><span class="muted">{{ $pending->count() }}</span></div>
@foreach($pending as $item)
<article class="panel">
<div class="row between">
<h3>{{ $item->title }}</h3>
<span class="badge">{{ $item->state() === 'draft' ? 'Draft' : 'Scheduled' }}</span>
</div>
<p class="muted">{{ $item->course?->title ?? 'Platform announcement' }}@if($item->state() === 'scheduled') · appears @showtime($item->published_at)@endif</p>
<div class="prose">{{ $item->body }}</div>
<details><summary>Edit before it is published</summary>
<form method="post" action="{{ route('announcements.update', $item) }}">@csrf @method('patch')
<label>Title<input name="title" required maxlength="160" value="{{ $item->title }}"></label>
<label>Message<textarea name="body" required maxlength="10000" rows="5">{{ $item->body }}</textarea></label>
<fieldset class="choice-group"><legend>When should this appear?</legend>
<label><input type="radio" name="state" value="now"> Publish now</label>
<label><input type="radio" name="state" value="schedule" @checked($item->state() === 'scheduled')> Keep scheduled</label>
<label><input type="radio" name="state" value="draft" @checked($item->state() === 'draft')> Keep as a draft</label>
</fieldset>
<label>Publication time (@tz)<input type="datetime-local" name="publish_at" value="{{ \App\Services\DisplayTime::forInput($item->published_at) }}"></label>
<button class="button">Save changes</button></form></details>
<form method="post" action="{{ route('announcements.discard', $item) }}" data-confirm="Discard &ldquo;{{ $item->title }}&rdquo;? It has not been published, so nobody has seen it.">@csrf @method('delete')<button class="button secondary">Discard</button></form>
</article>
@endforeach
@endif
@forelse($announcements as $announcement)<article class="panel"><h2>{{ $announcement->title }}</h2><p class="muted">{{ $announcement->course?->title ?? 'Platform announcement' }} · {{ $announcement->author?->name ?? 'Staff' }} · @showtime($announcement->published_at)</p><div class="prose">{{ $announcement->body }}</div>@if(!$announcement->is_read)<form method="post" action="{{ route('announcements.read', $announcement) }}">@csrf<span class="badge">Unread</span> <button class="button secondary">Mark as read</button></form>@else<p class="muted">Read</p>@endif</article>@empty<p class="panel">No announcements for you yet.</p>@endforelse
{{ $announcements->links() }}
@endsection
