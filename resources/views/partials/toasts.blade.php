{{--
  One place where every transient message is rendered.

  Server-flashed messages are written into the markup, so they are readable before any script
  runs and remain readable if scripting is unavailable; app.js only adds dismissal and timing.
  Live regions are split by urgency: successes are announced politely, problems assertively.
--}}
<div class="toast-region" data-toasts>
<div class="toast-live" role="status" aria-live="polite" aria-atomic="false">
@if(session('status'))
<div class="toast toast-success" data-toast data-toast-auto="8000">
<p>{{ session('status') }}</p>
<button type="button" class="toast-close" data-toast-dismiss aria-label="Dismiss this message">×</button>
</div>
@endif
</div>
<div class="toast-live" role="alert" aria-live="assertive" aria-atomic="false">
@foreach($errors->all() as $error)
<div class="toast toast-error" data-toast>
<p>{{ $error }}</p>
<button type="button" class="toast-close" data-toast-dismiss aria-label="Dismiss this message">×</button>
</div>
@endforeach
</div>
</div>
