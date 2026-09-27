const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/app.js'), 'utf8');

function boot(selectors = {}) {
 const listeners = {};
 const requests = [];
 const redirects = [];
 const document = {
  addEventListener: (name, fn) => { listeners[name] = fn; },
  querySelector: selector => selectors[selector] || null,
  querySelectorAll: () => [],
  getElementById: () => null,
  createElement: () => ({ value: '', textContent: '' }),
 };
 class Request {
  constructor() { this.upload = {}; requests.push(this); }
  open() {}
  setRequestHeader() {}
  send(body) { this.body = body; }
 }
 vm.runInNewContext(source, { document, XMLHttpRequest: Request, FormData: class {}, URL,
  location: { href: 'https://lms.test/courses/1/lectures', origin: 'https://lms.test', assign: url => redirects.push(url) },
  window: { confirm: () => true, setTimeout: () => {} },
 });
 return { listeners, requests, redirects };
}

function upload(runtime, size = 100, max = 0) {
 const status = { textContent: '' };
 const button = { disabled: false };
 const progress = { hidden: true, value: 0 };
 const form = {
  dataset: { maxUploadBytes: String(max) }, action: '/upload',
  hasAttribute: name => name === 'data-upload', setAttribute() {}, removeAttribute() {},
  querySelector: name => name === '[data-upload-status]' ? status : name === '[data-upload-progress]' ? progress : name.startsWith('input') ? { files: [{ size }] } : button,
 };
 runtime.listeners.submit({ target: form, submitter: button, preventDefault() {} });
 return { status, button, form };
}

test('large uploads have no fixed two-minute timeout and redirect on success', () => {
 const runtime = boot();
 upload(runtime);
 assert.equal(runtime.requests[0].timeout, 0);
 Object.assign(runtime.requests[0], { status: 200, responseText: '{"redirect":"https://lms.test/lectures/1"}' });
 runtime.requests[0].onload();
 assert.deepEqual(runtime.redirects, ['https://lms.test/lectures/1']);
});

test('known PHP ceiling prevents a doomed upload before sending bytes', () => {
 const runtime = boot();
 const ui = upload(runtime, 10 * 1048576, 2 * 1048576);
 assert.equal(runtime.requests.length, 0);
 assert.match(ui.status.textContent, /2.0 MB/);
 assert.equal(ui.button.disabled, false);
});

test('HTML 413 and expired-session errors are actionable and allow retry', () => {
 for (const [code, expected] of [[413, /PHP post_max_size/], [419, /session expired/]]) {
  const runtime = boot();
  const ui = upload(runtime);
  Object.assign(runtime.requests[0], { status: code, responseText: '<html>Error</html>' });
  runtime.requests[0].onload();
  assert.match(ui.status.textContent, expected);
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.form.dataset.submitting, undefined);
 }
});

test('provider changes replace options and preserve the choice for each provider', () => {
 const provider = { value: 'openrouter', addEventListener(_, fn) { this.change = fn; } };
 const originals = [
  { dataset: { provider: 'openrouter' }, value: 'a:free', textContent: 'Free A' },
  { dataset: { provider: 'openrouter' }, value: 'b:free', textContent: 'Free B' },
  { dataset: { provider: 'openai' }, value: 'paid', textContent: 'Paid model' },
 ];
 const model = { value: 'a:free', options: [], selectedOptions: [originals[0]],
  querySelectorAll: () => originals, replaceChildren() { this.options = []; },
  appendChild(option) { this.options.push(option); }, addEventListener(_, fn) { this.change = fn; },
 };
 boot({ '[data-ai-provider]': provider, '[data-ai-model]': model });
 assert.deepEqual(model.options.map(o => o.value), ['a:free', 'b:free']);
 model.value = 'b:free'; model.change();
 provider.value = 'openai'; provider.change();
 assert.deepEqual(model.options.map(o => o.value), ['paid']);
 provider.value = 'openrouter'; provider.change();
 assert.equal(model.value, 'b:free');
 provider.value = 'mock'; provider.change();
 assert.deepEqual(model.options.map(o => o.value), ['']);
});
