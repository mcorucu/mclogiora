# 0018 — Translation Suggestions

## Status

Accepted. Implemented in Phase 16 (v0.15.0).

## Context

Every phase before this one moved text that a person had written. Phase 16 is the
first that can put text on a site which nobody wrote, and the first that can send a
site's content to a company that is not the site's host.

Those two facts set the whole design. A translation plugin that calls a language
model is not primarily a provider-integration problem; it is a problem of consent,
attribution and blast radius. The interesting decisions are about what the feature
refuses to do.

Three pressures pull in the wrong direction and were resolved deliberately:

- **Convenience pulls toward automation.** Translating on save, or on publish, or in
  bulk, is what the feature "obviously" wants to become. It is also how a site ends
  up publishing machine text nobody read.
- **Feature count pulls toward document translation.** Translating a whole post body
  reads as the headline capability. Serialized blocks and builder payloads make it a
  silent-corruption problem rather than a translation problem.
- **Convenience also pulls toward a hosted proxy.** Routing requests through a
  vendor service is easier to build and easier to bill. It also makes the plugin's
  author a processor of every customer's content.

## Decision

### Optional, off, and never load-bearing

Suggestions are an optional subsystem. The master switch defaults to off, no
provider is selected, and a site that never touches the settings screen behaves
exactly as it did in Phase 15. Manual translation remains complete and unaffected:
the feature adds a way to draft text, never a dependency for producing it.

Nothing is contacted at boot, on activation, on an editor load, on an admin page
load, or on a front-end request. The only thing that reaches a provider is an
explicit click.

### Bring your own credentials, direct from site to provider

The site owner supplies their own API key and is billed by the provider directly.
mcLogiora ships no keys, no credits, no account, and no gateway. Requests go from
the WordPress site to the provider the owner chose.

There is deliberately no mcLogiora hosted service in the path. That keeps the
plugin's author out of the position of processing other people's content, and it
means the feature cannot break because a vendor endpoint went away.

### Provider-neutral interface, four adapters, WordPress HTTP APIs

`TranslationProviderInterface` defines what the domain needs — is this provider
configured, what models does it offer, translate this text, check this credential —
and each provider implements it. `ProviderRegistry` holds them; nothing outside an
adapter knows a provider's request or response shape.

No vendor SDKs. Every request goes through the WordPress HTTP API behind a
`TransportInterface`, which keeps the plugin installable without Composer
dependencies at runtime, keeps it reviewable, and makes the transport substitutable
in tests without touching provider logic.

Four adapters ship: OpenAI, Anthropic, Google Gemini and DeepL.

### Explicit model selection, never inferred

For the three LLM providers the owner must fetch the model list and choose a model.
Until they do, the provider reports `MODEL_REQUIRED` and suggestions stay
unavailable.

No model is ever selected automatically, including "sensible defaults". Model choice
determines cost and quality, and a plugin that picks one silently is spending
somebody else's money on an opinion they did not express. When a stored model
disappears from a refreshed list the selection is cleared and the owner is told,
rather than quietly substituted.

DeepL is a dedicated translation service with no model concept, so it has no model
selector and says so on screen.

### OpenAI: Responses API with `store` pinned to false

The OpenAI adapter uses the Responses API and sends `store => false` on every
request, as a policy constant (`REQUIRED_STORE`) rather than a default parameter.
A unit test asserts the key is present and that the value is boolean `false`, not
merely falsy.

No stateful OpenAI features are used: no `previous_response_id`, no `conversation`,
no `background`, no tools, no files, no attachments. Each request carries the one
field being translated and nothing else.

What mcLogiora can honestly claim is that it explicitly asks for no retention. It
cannot make claims on OpenAI's behalf about what OpenAI does, and the documentation
does not.

### Gemini and Anthropic: response shapes stay inside adapters

Gemini uses `generateContent` on the v1beta endpoint with the key in the
`x-goog-api-key` header rather than a query parameter, so it does not land in
server access logs as part of the URL. Anthropic uses the Messages endpoint with an
explicit `anthropic-version` header.

Both providers' request and response structures are confined to their adapters. The
service layer sees a `SuggestionResult`.

### DeepL: placeholders survive by construction

DeepL is asked to treat the payload as XML and to ignore a specific tag. mcLogiora
wraps every protected placeholder in that tag, so DeepL does not translate what is
inside it. Free and Pro keys live on different hosts and are distinguished by the
documented `:fx` suffix, so the owner does not have to know which host to use.

### PlaceholderShield

Interface strings and content routinely contain `%s`, `%1$s`, `{name}` and similar.
A model asked to translate `Hello %1$s` will sometimes return `Hola %1$ s`, or
reorder positional arguments, or drop one — and a broken placeholder is not a typo,
it is a fatal `sprintf` or a wrong value in production.

`PlaceholderShield` replaces each placeholder with an opaque token before the
request leaves, restores them from the answer, and **refuses the suggestion** if any
token failed to come back. A refusal is reported in terms of the placeholder the
user recognises (`%1$s`), never the internal token.

Refusing is the point. A suggestion that silently lost a placeholder is worse than
no suggestion.

### CredentialStore, wp-config precedence, and an honest storage statement

Credentials are read from a constant first — `MCLOGIORA_OPENAI_API_KEY`,
`MCLOGIORA_ANTHROPIC_API_KEY`, `MCLOGIORA_GEMINI_API_KEY`,
`MCLOGIORA_DEEPL_API_KEY` — and only then from the database. When a constant is
present the settings screen says so and does not pretend the field is editable.

Keys entered in the admin are stored in the options table as entered. The plugin
does **not** claim to encrypt them. Any "encryption" available to a plugin keeps the
key material next to the ciphertext, so it would obstruct a casual reader and
mislead a careful one. The honest position is stated plainly, with the wp-config
constant offered as the hardened path.

The admin never redisplays a stored key. It shows a masked suffix so the owner can
recognise which key is installed, and nothing more.

### Server-authoritative source resolution, and no quota proxy

This is the security decision that shapes every surface.

The browser sends an object id, a field name, and — for the admin surfaces — a
target language. It never sends the text to be translated. The server resolves the
authoritative source itself: the source post through the translation relation, the
source term through its group, the registered string by its row, the attachment's
own metadata.

If the browser could supply the text, the endpoint would be a general-purpose
translation proxy funded by the site owner's quota and reachable by anyone who can
open an editor. Tests submit `text`, `sourceText`, `source`, `content` and `value`
and assert the provider payload still contains only the real source value.

### Generate mutates nothing; Apply is the only write

`Generate` calls the provider and stores a preview. It does not touch the target
field, the source, the relation status, the post's dirty state, or anything else.
`Apply` is a separate explicit action.

There is no auto-apply, no apply-on-save, no bulk translate, and no path by which
generating a suggestion can publish anything.

### Preview tokens

A successful `Generate` produces a short-lived preview bound to the user, the object
type, the source id, the target id, the surface and the target language. `Apply`
sends the token, and the server applies the stored preview text — not text from the
browser, which would reopen the hole that server-side resolution closed.

Applying consumes the token. Discarding invalidates it. A token for one field cannot
be applied to another, a token for one object cannot be applied to a different one,
and an expired or consumed token is refused.

Previous previews for the same field stay valid until they expire rather than being
invalidated by a regenerate, so a reviewer who preferred the earlier wording has not
lost it.

### Apply writes narrowly, and rolls back

Each surface writes exactly the field asked for.

- Post and term applies write the field, then record the review status. If the
  status cannot be recorded the field is put back, because machine text sitting
  under a status that says a human approved it is a silent lie that survives until
  somebody notices the wording.
- Term applies never pass a slug to `wp_update_term()`. A machine-translated slug
  would change every URL the term owns, silently.
- Media applies read all four metadata values and write all four, because the media
  service replaces the whole per-language row. Writing only the requested field
  would blank the other three.

### Status semantics differ per domain, and the documentation says so

The three storage models genuinely differ, and the feature reports what each one
actually holds:

| Domain | Status after an applied suggestion |
| --- | --- |
| Post, page, CPT, taxonomy term | relation status `machine_suggested` |
| Interface string | `MACHINE_SUGGESTED` in the string schema |
| Media metadata | `translated` in `MediaTranslationService` |

Media is the honest exception. `MediaTranslationService::save()` records
`TRANSLATED` and the media schema has no machine-suggested state. Reporting
`machine_suggested` there for visual consistency would make the screen disagree with
the database. Giving media that state is a schema change, and Phase 16 does not
change schema.

Relation-backed content therefore requires an explicit human transition from
`machine_suggested` to `translated`. A suggestion is never treated as approval.

### Field-level only; document translation deferred

Phase 16 translates named fields: post title and excerpt, term name and description,
interface strings, and attachment title, alternative text, caption and description.

It does not translate raw `post_content`, block documents as a whole, Elementor or
Beaver Builder payloads, or arbitrary meta. Phase 15 proved those structures survive
translation *because mcLogiora copies them untouched*. Handing a serialized block
document to a model and reassembling the answer is a different problem with a silent
failure mode: a corrupted delimiter does not throw, it renders the page as literal
HTML comments.

That needs an extraction and reassembly layer proven against real payloads from
every builder Phase 15 qualified. Until it exists, this is documented as deferred
rather than shipped partially, because "we translate your pages, mostly" is a worse
promise than "we translate these fields, exactly".

### No telemetry

No usage reporting, no beacons, no version pings, no error reporting to any
mcLogiora endpoint. The only outbound traffic the subsystem can generate is a
provider request the owner explicitly triggered.

## Consequences

**Accepted costs**

- The feature is slower to use than an automatic one. Every field is a click, a
  read and a second click. That is the intended trade.
- Four adapters and no SDKs means provider changes land as maintenance here. The
  narrow interface keeps the blast radius inside one file.
- Requiring explicit model selection adds a setup step and will generate support
  questions from owners who expected a default.
- Refusing suggestions on placeholder loss will occasionally reject an otherwise
  good translation. Preferred over shipping a broken `sprintf`.
- Media's status differs from the other surfaces, which is a genuine inconsistency
  in the product surface. Documented rather than papered over.
- No document translation means the most-requested capability is absent at launch.

**Gained**

- A site that does not configure the feature is byte-for-byte unaffected.
- No content can leave a site without an explicit action by a capable user.
- The endpoints cannot be used as a translation proxy against the owner's quota.
- Machine text cannot reach a published translation without a human transition, on
  the surfaces whose schema can express that.
- The transport seam that made the subsystem testable also made it qualifiable in a
  real browser without a single live provider call.

**Not verified**

Live provider qualification has not been performed. Every provider adapter is
covered by unit tests against recorded response shapes, and the browser
qualification used a deterministic local transport double. No request has been made
to OpenAI, Anthropic, Google or DeepL with a real credential. That remains an
explicit gap and is stated as such in the qualification record.

## Phase 18 requalification expectations

- Live provider qualification with real credentials, per provider, including at
  least one representative failure per normalized error category.
- Re-run the placeholder refusal path against each live provider, since placeholder
  fidelity is a model behaviour rather than an adapter behaviour.
- Revisit the media status model: either give media a review state in schema or
  document the divergence as permanent.
- Language-switcher block registration is not idempotent when `init` is fired more
  than once artificially. The normal WordPress lifecycle does not do this, so it is
  carried as a low-severity observation rather than a Phase 16 blocker.
