@extends('layouts.app')
@section('title', 'Announcements')
@section('content')
<div class="page-heading"><div><h1>Announcements</h1><p class="muted">Platform updates and messages for your courses.</p></div></div>
@if(auth()->user()->role === 'admin')<details class="panel"><summary>Publish a platform announcement</summary><form method="post" action="{{ route('announcements.platform') }}" data-confirm="Publish this announcement to every active account?">@csrf<label>Title<input name="title" required maxlength="160" value="{{ old('title') }}"></label><label>Message<textarea name="body" required maxlength="10000" rows="5">{{ old('body') }}</textarea></label><button class="button">Publish announcement</button></form></details>@endif
@forelse($announcements as $announcement)<article class="panel"><h2>{{ $announcement->title }}</h2><p class="muted">{{ $announcement->course?->title ?? 'Platform announcement' }} · {{ $announcement->author?->name ?? 'Staff' }} · {{ $announcement->published_at->format('M j, Y H:i') }} UTC</p><div class="prose">{{ $announcement->body }}</div>@if(!$announcement->is_read)<form method="post" action="{{ route('announcements.read', $announcement) }}">@csrf<span class="badge">Unread</span> <button class="button secondary">Mark as read</button></form>@else<p class="muted">Read</p>@endif</article>@empty<p class="panel">No announcements for you yet.</p>@endforelse
{{ $announcements->links() }}
@endsection
