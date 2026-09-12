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
