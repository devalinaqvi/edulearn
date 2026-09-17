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
