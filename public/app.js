// ---- Transient messages --------------------------------------------------
// Server-rendered toasts are already in the markup; this adds dismissal, timing, and a single
// entry point so background requests report failures the same way a redirect does.
const toastRegion = document.querySelector('[data-toasts]');

const dismissToast = (toast) => {
 if (toast && toast.parentNode) { toast.remove(); }
};

document.addEventListener('click', (event) => {
 const button = event.target.closest('[data-toast-dismiss]');
 if (button) { dismissToast(button.closest('[data-toast]')); }
});

// Successes clear themselves; problems stay until they are acknowledged.
for (const toast of document.querySelectorAll('[data-toast][data-toast-auto]')) {
 const delay = Number(toast.dataset.toastAuto) || 8000;
 let timer = window.setTimeout(() => dismissToast(toast), delay);
 // Reading or interacting with a message should not race a timer.
 toast.addEventListener('mouseenter', () => window.clearTimeout(timer));
 toast.addEventListener('focusin', () => window.clearTimeout(timer));
 toast.addEventListener('mouseleave', () => { timer = window.setTimeout(() => dismissToast(toast), delay); });
}

window.lmsToast = (message, kind = 'error') => {
 if (!toastRegion || !message) { return; }
 const live = toastRegion.querySelector(kind === 'success' ? '[aria-live="polite"]' : '[aria-live="assertive"]');
 if (!live) { return; }
 const toast = document.createElement('div');
 toast.className = 'toast toast-' + (kind === 'success' ? 'success' : 'error');
 toast.setAttribute('data-toast', '');
 const text = document.createElement('p');
 text.textContent = message;
 const close = document.createElement('button');
 close.type = 'button';
 close.className = 'toast-close';
 close.setAttribute('data-toast-dismiss', '');
 close.setAttribute('aria-label', 'Dismiss this message');
 close.textContent = '\u00d7';
 toast.append(text, close);
 live.appendChild(toast);
 if (kind === 'success') { window.setTimeout(() => dismissToast(toast), 8000); }
 return toast;
};

document.addEventListener('submit', (event) => {
 const form = event.target;
 if (form.dataset.submitting === 'true') {
  event.preventDefault();
  return;
 }
 if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
  event.preventDefault();
  return;
 }
 if (form.hasAttribute('data-upload')) {
  event.preventDefault();
  const button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
  const progress = form.querySelector('[data-upload-progress]');
  const status = form.querySelector('[data-upload-status]');
  const file = form.querySelector('input[type="file"]')?.files?.[0];
  const maxBytes = Number(form.dataset.maxUploadBytes || 0);
  if (file && maxBytes > 0 && file.size > maxBytes) {
   const message = 'This recording exceeds this server’s upload ceiling (' + (maxBytes / 1048576).toFixed(1) + ' MB). Ask the administrator to raise PHP and web server limits, or choose a smaller file.';
   status.textContent = message;
   if (window.lmsToast) { window.lmsToast(message, 'error'); }
   return;
  }
  const xhr = new XMLHttpRequest();
  form.dataset.submitting = 'true';
  form.setAttribute('aria-busy', 'true');
  if (button) button.disabled = true;
  progress.hidden = false;
  progress.value = 0;
  status.textContent = 'Uploading…';
  xhr.open('POST', form.action);
  xhr.setRequestHeader('Accept', 'application/json');
  // Large recordings can take many minutes; do not abort an active transfer after two minutes.
  xhr.timeout = 0;
  xhr.upload.onprogress = (e) => {
   if (e.lengthComputable) progress.value = Math.round(e.loaded / e.total * 100);
   status.textContent = progress.value === 100 ? 'Upload received. Validating file…' : 'Uploading… ' + progress.value + '%';
  };
  const failed = (message) => {
   delete form.dataset.submitting;
   form.removeAttribute('aria-busy');
   if (button) button.disabled = false;
   status.textContent = message;
   // Same presentation as a server-flashed problem, instead of text only this form can show.
   if (window.lmsToast) { window.lmsToast(message, 'error'); }
  };
  xhr.onload = () => {
   let result = {};
   try { result = JSON.parse(xhr.responseText); } catch (_) {}
   if (xhr.status >= 200 && xhr.status < 300 && result.redirect && new URL(result.redirect, location.href).origin === location.origin) {
    location.assign(result.redirect);
   } else {
    const messages = {413: 'The server rejected this upload as too large. Ask the administrator to raise PHP post_max_size/upload_max_filesize and the web server request limit.', 419: 'Your session expired. Reload this page and sign in before retrying.', 401: 'Sign in again before uploading.', 403: 'You no longer have permission to upload to this course.', 500: 'The server could not save this upload. Ask the administrator to check private storage permissions, disk space and server logs.'};
    failed(result.errors ? Object.values(result.errors).flat().join(' ') : (messages[xhr.status] || 'Upload failed. Check the file and try again.'));
   }
  };
  xhr.onerror = () => failed('Connection lost. Check the course before retrying.');
  xhr.ontimeout = () => failed('The upload timed out. Check the course before retrying.');
  xhr.send(new FormData(form));
  return;
 }
 const button = event.submitter;
 if (button && form.method.toLowerCase() === 'post') {
  const originalText = button.textContent;
  form.dataset.submitting = 'true';
  form.setAttribute('aria-busy', 'true');
  // Keep the submitter enabled so its name and value reach the server.
  button.setAttribute('aria-disabled', 'true');
  button.textContent = 'Saving…';
  window.setTimeout(() => {
   delete form.dataset.submitting;
   form.removeAttribute('aria-busy');
   button.removeAttribute('aria-disabled');
   button.textContent = originalText;
  }, 15000);
 }
});

for (const timer of document.querySelectorAll('[data-quiz-seconds]')) {
 const started = performance.now();
 const seconds = Number(timer.dataset.quizSeconds);
 const update = () => {
  const left = Math.max(0, Math.ceil(seconds - (performance.now() - started) / 1000));
  timer.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') + ' remaining';
  if (left === 0) {
   clearInterval(interval);
   const form = document.querySelector('[data-quiz-attempt]');
   if (form && form.dataset.submitting !== 'true') form.requestSubmit(form.querySelector('[name="action"][value="submit"]'));
  }
 };
 const interval = setInterval(update, 1000);
 update();
}

// Video lecture player: resume position, bounded forward-progress reporting, speed control.
const player = document.getElementById('lecture-player');
if (player) {
 const status = document.querySelector('[data-player-status]');
 const speed = document.querySelector('[data-player-speed]');
 const bar = document.querySelector('[data-watched-bar]');
 const label = document.querySelector('[data-watched-label]');
 const canRecord = player.dataset.canRecord === '1';
 const token = document.querySelector('meta[name="csrf-token"]')?.content;
 let lastReported = 0;
 let sending = false;

 const say = (text) => { if (status) status.textContent = text; };

 player.addEventListener('loadedmetadata', () => {
  const resume = Number(player.dataset.resumeAt || 0);
  if (resume > 0 && resume < player.duration - 1) {
   player.currentTime = resume;
   say('Resumed where you left off.');
  }
  lastReported = player.currentTime;
 });
 player.addEventListener('waiting', () => say('Buffering…'));
 player.addEventListener('playing', () => say(''));
 player.addEventListener('error', () => say('This lecture could not be played. Reload the page, or contact your instructor if it keeps failing.'));

 if (speed) {
  speed.addEventListener('change', () => { player.playbackRate = Number(speed.value); });
 }

 const report = (final) => {
  if (!canRecord || sending || !token) { return; }
  const current = Math.floor(player.currentTime);
  // Credit only real elapsed forward playback; the server clamps this against its own record.
  const delta = Math.max(0, Math.min(120, current - Math.floor(lastReported)));
  if (!final && delta < 5) { return; }
  sending = true;
  lastReported = current;
  fetch(player.dataset.progressUrl, {
   method: 'POST',
   headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
   body: JSON.stringify({ position_seconds: current, watched_delta: delta }),
   keepalive: final,
  }).then((r) => r.ok ? r.json() : null).then((result) => {
   sending = false;
   if (!result || !player.duration) { return; }
   const percent = Math.min(100, Math.round(100 * result.watched_seconds / player.duration));
   if (bar) { bar.value = percent; }
   if (label) { label.textContent = percent + '%'; }
   if (result.completed) { say('Lecture complete.'); }
  }).catch(() => { sending = false; });
 };

 window.setInterval(() => { if (!player.paused) { report(false); } }, 15000);
 player.addEventListener('pause', () => report(true));
 player.addEventListener('ended', () => report(true));
 player.addEventListener('seeking', () => { lastReported = player.currentTime; });
 document.addEventListener('visibilitychange', () => { if (document.hidden) { report(true); } });
}

// Rebuild the model options: hiding optgroups is inconsistent in native Windows selects.
const aiProvider = document.querySelector('[data-ai-provider]');
const aiModel = document.querySelector('[data-ai-model]');
if (aiProvider && aiModel) {
 const options = Array.from(aiModel.querySelectorAll('option[data-provider]')).map(option => ({
  provider: option.dataset.provider, value: option.value, label: option.textContent
 }));
 const choices = {};
 const selected = aiModel.selectedOptions[0];
 if (selected?.dataset.provider) choices[selected.dataset.provider] = selected.value;
 const apply = () => {
  const provider = aiProvider.value;
  const available = options.filter(option => option.provider === provider);
  aiModel.replaceChildren();
  for (const option of available) {
   const element = document.createElement('option');
   element.value = option.value;
   element.textContent = option.label;
   aiModel.appendChild(element);
  }
  if (!available.length) {
   const placeholder = document.createElement('option');
   placeholder.value = '';
   placeholder.textContent = provider === 'mock' ? 'Not applicable (development mock)' : 'Pull this provider’s catalogue below to select a model';
   aiModel.appendChild(placeholder);
  }
  aiModel.value = available.some(option => option.value === choices[provider]) ? choices[provider] : (available[0]?.value || '');
 };
 aiModel.addEventListener('change', () => { choices[aiProvider.value] = aiModel.value; });
 aiProvider.addEventListener('change', apply);
 apply();
}

// ---- Lesson rich text ----------------------------------------------------
// Progressive enhancement over the textarea that already works: with scripting unavailable the
// plain field is submitted as-is and stored as text. When this runs, an editable surface takes
// its place, the textarea is kept in sync as the authoritative form value, and the format flag
// switches to html. Nothing here is a security control — the server sanitizes on every save.
for (const host of document.querySelectorAll('[data-editor]')) {
 const source = host.querySelector('[data-editor-source]');
 const toolbar = host.querySelector('[data-editor-toolbar]');
 const format = host.querySelector('[data-editor-format]');
 if (!source || !toolbar || !format || !document.execCommand) { continue; }

 const surface = document.createElement('div');
 surface.className = 'editor-surface prose';
 surface.contentEditable = 'true';
 surface.setAttribute('role', 'textbox');
 surface.setAttribute('aria-multiline', 'true');
 surface.setAttribute('aria-label', 'Lesson content');
 surface.tabIndex = 0;

 // Existing plain-text lessons become paragraphs rather than one run-on block.
 if (format.value === 'html') {
  surface.innerHTML = source.value;
 } else {
  for (const paragraph of source.value.split(/\n{2,}/)) {
   const p = document.createElement('p');
   p.textContent = paragraph.trim();
   if (p.textContent) { surface.appendChild(p); }
  }
  if (!surface.childNodes.length) { surface.appendChild(document.createElement('p')); }
 }

 const wasRequired = source.hasAttribute('required');
 source.hidden = true;
 source.removeAttribute('required');
 surface.setAttribute('aria-required', wasRequired ? 'true' : 'false');
 host.insertBefore(surface, source);

 const sync = () => {
  source.value = surface.innerHTML;
  format.value = 'html';
 };
 surface.addEventListener('input', sync);
 surface.addEventListener('blur', sync);
 host.closest('form')?.addEventListener('submit', (event) => {
  sync();
  // The browser can no longer enforce `required` on a hidden field, so stand in for it.
  if (wasRequired && !surface.textContent.trim() && !surface.querySelector('img,hr')) {
   event.preventDefault();
   surface.focus();
   if (window.lmsToast) { window.lmsToast('Write the lesson content before saving.', 'error'); }
  }
 });
 sync();

 const refreshState = () => {
  for (const button of toolbar.querySelectorAll('[aria-pressed]')) {
   let active = false;
   try { active = document.queryCommandState(button.dataset.command); } catch (_) {}
   button.setAttribute('aria-pressed', active ? 'true' : 'false');
  }
 };
 document.addEventListener('selectionchange', () => {
  if (document.activeElement === surface) { refreshState(); }
 });

 toolbar.addEventListener('click', (event) => {
  const button = event.target.closest('[data-command]');
  if (!button) { return; }
  event.preventDefault();
  surface.focus();
  const command = button.dataset.command;
  if (command === 'createLink') {
   const url = window.prompt('Link address (https://…)');
   if (!url) { return; }
   // Only schemes the server will keep; anything else is refused before it is inserted.
   if (!/^(https?:|mailto:)/i.test(url)) {
    if (window.lmsToast) { window.lmsToast('Links must start with https://, http:// or mailto:.', 'error'); }
    return;
   }
   document.execCommand('createLink', false, url);
  } else if (button.dataset.value) {
   document.execCommand(command, false, button.dataset.value);
  } else {
   document.execCommand(command, false, null);
  }
  sync();
  refreshState();
 });

 // Pasting carries the clipboard's markup; take the text and let the author format it.
 surface.addEventListener('paste', (event) => {
  event.preventDefault();
  const text = (event.clipboardData || window.clipboardData).getData('text/plain');
  document.execCommand('insertText', false, text);
 });
}
