# AI providers — models, credentials and data policy

## Model selection

The model field is a **dropdown**, not free text. Options are rebuilt for the selected provider by a small script so native Windows selects do not depend on hiding optgroups; without JavaScript every group stays visible and the
form still works.

Catalogues are pulled on demand and cached for 15 minutes:

| Button | Source | Credential |
| --- | --- | --- |
| Pull OpenRouter free models | `GET https://openrouter.ai/api/v1/models`, filtered to entries whose **every** published price is exactly `0` | none (public) |
| Pull OpenAI models | `GET https://api.openai.com/v1/models`, filtered to text-generation families and excluding embedding/moderation/tts/whisper/audio/realtime/image/transcribe models | required, must be saved first |

A model that is no longer in the pulled catalogue is **rejected on save**, never silently replaced.
A saved-but-withdrawn model still renders under "Currently saved (not in any pulled catalogue)" so
the form round-trips instead of losing the value. Providers withdraw free models often — a model
that worked last week may simply be gone.

## Zero data retention (important)

`require_zero_retention` defaults to **on**, which sends `zdr: true` to OpenRouter.

Availability is provider-dependent and changes. The public catalogue does not guarantee an endpoint matching the saved privacy restrictions. Keep the restriction unless an administrator deliberately approves a different retention policy. A 429 is reported as rate limiting; it is not assumed to prove a privacy-policy failure.

Turning it off may make more endpoints eligible; it does not guarantee availability. Data collection for training is refused either way
(`data_collection: deny`), fallbacks stay disabled, and `max_price` remains zero on every axis, so
a paid model is never reachable. What changes is that the serving provider may retain submitted
lesson and material text under its own policy. That is a deliberate, audited administrator choice
recorded in `account_activity`; it is acceptable for demo content and should be reconsidered
before real learner data.

## Diagnosing failures

Failures are classified into a fixed set of reasons shown under "Recent AI activity". Provider
response bodies and credentials are never stored or logged — only these authored strings:

| Reason | Meaning |
| --- | --- |
| data policy | No endpoint met the zero-retention requirement (see above) |
| model unavailable | The selected model is no longer zero price |
| rate limited | The model was rate-limited **upstream**; usually temporary and model-specific |
| auth failed | The stored credential was rejected |
| invalid output | Empty or oversized response |
| unreachable | The provider could not be reached |

"Test saved credentials" only authenticates; it proves nothing about whether generation will
succeed. A `connection_ok` followed by `failed` can indicate model capacity, rate limits, output validation, or routing/privacy restrictions. Inspect the recorded reason rather than inferring one from authentication success.

## Verified working configuration

Provider `openrouter`, model `openrouter/free` (Free Models Router), zero data retention **off**.
Confirmed end to end on 24 September 2026: a learner request produced a 2,350 character note
grounded in the source lesson and citing `[Source]`. Individual free models such as
`qwen/qwen3.8-27b:free` were rate-limited upstream at the time; the Free Models Router routes
around that.

## Installation troubleshooting

- Pulling OpenRouter uses the public catalogue and needs no API key. If it cannot connect, check server DNS, outbound HTTPS, and PHP cURL/OpenSSL trust certificates. Do not disable certificate verification. A failed refresh leaves the previous cached catalogue intact.
- After pulling, OpenRouter is selected in the form and its options become selectable. Save the configuration explicitly; selecting or pulling a provider alone does not enable it.
- Windows PHP may use different `php.ini` files for the CLI worker and the web server. Configure both as appropriate, then restart both processes.
- A copied database requires its original `APP_KEY` to decrypt stored credentials. If the key is unavailable, AI administration allows a replacement credential and displays a recovery notice; it does not silently erase the persisted ciphertext on a read.
- Notes that stay pending require `php artisan queue:work database --sleep=1 --tries=3 --timeout=45`. Restart long-running workers after deploying changes.
- Credentials belong in the password field, never in chat, URLs, Git or screenshots. Revoke any exposed credential.
