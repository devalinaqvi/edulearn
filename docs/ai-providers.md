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

First-time OpenAI configuration: select OpenAI, disable AI, save the credential with no model, pull the model catalogue, then select a model and enable AI with paid-use approval. A disabled configuration does not require a model or an available catalogue. Administrators can always disable AI during a provider outage.

## Windows certificate configuration

Command Prompt `curl` and PHP can use different certificate stores. For this app set `AI_CA_BUNDLE="C:/cacert.pem"` in `.env`, pointing to a current trusted PEM CA bundle readable by the web-server and worker accounts. `CURL_CAINFO` and `OPENSSL_CAFILE` are accepted as fallback environment names if `AI_CA_BUNDLE` is blank or absent. Laravel passes this path explicitly to all AI HTTPS requests; certificate verification stays enabled.

Run `php artisan optimize:clear`, restart Apache/PHP, and restart the queue worker. Alternatively configure `curl.cainfo="C:/cacert.pem"` and `openssl.cafile="C:/cacert.pem"` in the actual web-server/worker `php.ini` files. Setting those environment names alone on older code does not configure PHP ini. `php --ini` identifies the CLI configuration; Apache/FastCGI can load a different one. If the problem persists, verify DNS/proxy/firewall access from PHP itself rather than CMD curl. Do not share API keys or disable TLS verification.


## TLS and CA certificates (cross-device setup)

Every AI HTTPS call — catalogue pulls, the credential test and generation itself — goes through
`AiSettings::http()`. Nothing uses the `Http` facade directly, so there is one place where TLS
policy is decided and **verification is never disabled**.

On hosts without a CA trust store (Windows, and minimal containers) cURL cannot verify any
certificate, so *every* call fails before a credential is ever checked. Point `AI_CA_BUNDLE` at a
readable `cacert.pem`:

```dotenv
AI_CA_BUNDLE="C:/cacert.pem"
```

`CURL_CAINFO` and `OPENSSL_CAFILE` are read as fallbacks, so an existing setup keeps working.
Note these are **`.env` values read by `config/study.php`, not PHP ini directives** — setting them
in `.env` does not configure PHP itself.

The file must exist and be readable by **both** the web server and the queue worker; they often run
as different accounts. If it is missing or unreadable the request fails immediately, before any
network call, with a message naming the setting. Leave the value empty to use the system trust
store.

### Failures are attributed correctly

A host that cannot be reached is never reported as a bad credential:

| Situation | Reported as |
| --- | --- |
| DNS, network or TLS failure | "Cannot reach …" plus what to check, including `AI_CA_BUNDLE` |
| `AI_CA_BUNDLE` unreadable | Names the setting; no request is sent |
| HTTP 401/403 | The provider rejected the stored credential |
| Other HTTP error | The provider refused the request, with the status |

This distinction matters because the symptom is identical from the outside: "Test saved
credentials" fails. Blaming the key sends an administrator to rotate a credential that was never
the problem. The reason is also written to `ai_usage_events.detail` and shown in Recent AI
activity.
