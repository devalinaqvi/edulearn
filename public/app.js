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
  const button = event.submitter;
  const progress = form.querySelector('[data-upload-progress]');
  const status = form.querySelector('[data-upload-status]');
  const xhr = new XMLHttpRequest();
  form.dataset.submitting = 'true';
  form.setAttribute('aria-busy', 'true');
  button.disabled = true;
  progress.hidden = false;
  progress.value = 0;
  status.textContent = 'Uploading…';
  xhr.open('POST', form.action);
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.timeout = 120000;
  xhr.upload.onprogress = (e) => {
   if (e.lengthComputable) progress.value = Math.round(e.loaded / e.total * 100);
   status.textContent = progress.value === 100 ? 'Upload received. Validating file…' : 'Uploading… ' + progress.value + '%';
  };
  const failed = (message) => {
   delete form.dataset.submitting;
   form.removeAttribute('aria-busy');
   button.disabled = false;
   status.textContent = message;
  };
  xhr.onload = () => {
   let result = {};
   try { result = JSON.parse(xhr.responseText); } catch (_) {}
   if (xhr.status >= 200 && xhr.status < 300 && result.redirect && new URL(result.redirect, location.href).origin === location.origin) {
    location.assign(result.redirect);
   } else {
    failed(result.errors ? Object.values(result.errors).flat().join(' ') : 'Upload failed. Check the file and try again.');
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
