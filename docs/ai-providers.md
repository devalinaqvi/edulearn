# AI providers — models, credentials and data policy

## Model selection

The model field is a **dropdown**, not free text. Options are grouped per provider and filtered
to the selected provider by a small script; without JavaScript every group stays visible and the
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

**No zero-price OpenRouter model currently offers a zero-retention endpoint.** Verified on
24 September 2026 by querying `/api/v1/models/{id}/endpoints` for all 24 zero-price models: none
reported a no-retention data policy. With the constraint on, every free generation fails — often
as a bare `429 Provider returned error` rather than a clear policy message, which is why a failure
while the constraint is on is classified as a data-policy problem.

Turning it off lets free models work. Data collection for training is refused either way
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
succeed. A `connection_ok` followed by repeated `failed` is the signature of a routing or data
policy problem, not a bad key.

## Verified working configuration

Provider `openrouter`, model `openrouter/free` (Free Models Router), zero data retention **off**.
Confirmed end to end on 24 September 2026: a learner request produced a 2,350 character note
grounded in the source lesson and citing `[Source]`. Individual free models such as
`qwen/qwen3.8-27b:free` were rate-limited upstream at the time; the Free Models Router routes
around that.
