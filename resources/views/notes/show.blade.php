@extends('layouts.app')
@section('title','Study note')
@section('content')<a class="back-link" href="{{ route('notes.index') }}">← Notes library</a>
<div class="page-heading">
<div>
<span class="eyebrow">{{ $note->provider==='mock' ? 'DEVELOPMENT MOCK · NO AI CALL' : 'AI-GENERATED · CHECK THE SOURCE' }}</span>
<h1>{{ $note->title }}</h1>
<p class="muted">Source: {{ $note->source_title }} · {{ $note->generated_at ? 'Generated '.$note->generated_at->format('M j, Y H:i').' UTC' : 'Requested '.$note->created_at->format('M j, Y H:i').' UTC' }}</p>
</div>
<span class="badge {{ $note->status }}">{{ ucfirst($note->status) }}</span>
</div>
@if($note->status==='completed')<div class="notice">Use these notes as a revision aid. Verify them against <a href="{{ route('courses.show',$note->course_id) }}{{ $note->lesson_id ? '#lesson-'.$note->lesson_id : '' }}">the original course source ↗</a>. [Source] refers only to “{{ $note->source_title }}”.</div>
<article class="panel note-output prose">{{ $note->content }}</article>@elseif($note->status==='failed')<div class="notice error">{{ $note->error }} You can delete this failed request and generate again; the daily limit still applies.</div>@else<section class="empty panel">
<span class="loading-dot">
</span>
<h2>{{ $note->status==='processing' ? 'Finding the important ideas…' : 'Your notes are in the queue.' }}</h2>
<p class="muted">{{ $note->error ?: 'You can leave this page and come back. Your request is saved.' }}</p>
<a class="button secondary" href="{{ route('notes.show',$note) }}">Refresh status ↻</a>
</section>@endif
<div class="two-col">
<form class="panel" method="post" action="{{ route('notes.update',$note) }}">@csrf @method('patch')<label>Note title<input name="title" value="{{ old('title',$note->title) }}" maxlength="200" required>
</label>
<button class="button secondary">Rename note</button>
</form>@if(in_array($note->status,['completed','failed']))<form class="panel" method="post" action="{{ route('notes.destroy',$note) }}" data-confirm="Delete this note permanently?">@csrf @method('delete')<h3>Remove from your library</h3>
<p class="muted">This permanently deletes this personal note.</p>
<button class="button danger">Delete note</button>
</form>@endif</div>@endsection
