# Translation Suggestions

Developer notes for the optional suggestion subsystem added in Phase 16 (v0.15.0).
The decision record is [ADR 0018](../adr/0018-translation-suggestions.md); this file
describes the seams and the rules a change has to keep.

Nothing here is loaded or reachable unless a site owner enables the feature and
configures either WordPress AI Client in Settings → Connectors or DeepL in
mcLogiora settings.

## Layers

```
Editor / admin surface  ── sends object id + field (+ language)
        │
SuggestionEditorController        SuggestionAdminController
  post_title, post_excerpt          string, term_*, media_*
        │                                   │
        └────────────┬──────────────────────┘
                     │  resolves the authoritative source itself
        TranslationSuggestionService  ── readiness, provider dispatch
                     │
        ProviderRegistry → TranslationProviderInterface
                     │            (WordPress AI Client, DeepL)
        PlaceholderShield ── protects placeholders across the round trip
                     │
              TransportInterface ── WordPress HTTP API
                     │
        SuggestionPreviewStore  ── short-lived, bound previews
                     │
        TranslationSuggestionApplyService ── the only writer
```

## Classes

**`TranslationProviderInterface`** — what the domain needs from a provider:
identity and label, whether it is configured, whether it requires model selection,
its available models, its selected model, a connection test, and a translation call.
A provider's request and response shapes never leave its adapter.

**`ProviderRegistry`** — holds the registered adapters and finds one by id. Add a
provider by registering an adapter here; nothing else needs to change.

**`SuggestionRequest` / `SuggestionResult`** — the provider-neutral value objects
crossing the adapter boundary. A result carries the translated text, the provider id
and the model used.

**`PlaceholderShield`** — replaces placeholders with opaque tokens before a request
leaves and restores them from the answer. If a token does not come back it reports a
failure naming the user-facing placeholder (`%1$s`), never the internal token. A
suggestion with a lost placeholder is refused, not repaired.

**`TranslationSuggestionService`** — the gate. Checks that the feature is enabled,
that a provider is chosen and configured, and that the surface is supported, then
dispatches to the adapter. Returns a `SuggestionResult` or a normalized `WP_Error`.

**`SuggestionPreviewStore`** — creates, reads, consumes and invalidates previews. A
preview is bound to the user, object type, source id, target id, surface and target
language, and expires on its own.

**`TranslationSuggestionApplyService`** — the only code that writes a suggestion.
One branch per domain, each writing exactly the field asked for. Post and term
branches write the field, then record review status, and put the field back if the
status cannot be recorded.

**`SuggestionEditorController`** — AJAX for post fields, where one post id is the
whole context. **`SuggestionAdminController`** — AJAX for the admin surfaces, where
the context is an id *and* a target language. Both validate before reaching a
provider.

**`SuggestionEditorState` / `SuggestionAdminState`** — build the only suggestion
data an editor or admin screen may see. Allow-lists of harmless facts: availability,
a reason when unavailable, provider and model labels, action names, a settings URL
and a nonce. No credential in any form, and no source text.

## Adding a surface

1. Add the constant to `SuggestionSurface` and to `all()`. Add it to `allows_html()`
   only if the field genuinely holds markup.
2. Add a branch to `TranslationSuggestionApplyService::apply_to_surface()` that
   writes exactly that field.
3. Add the surface to the relevant controller's allow-list and teach it to resolve
   the authoritative source value server-side.
4. Render the control. The admin script is data-attribute driven
   (`data-mclogiora-suggest`, `data-surface`, `data-object`, `data-language`,
   `data-field`), so a new admin surface needs no new JavaScript.

The allow-list is the security boundary. A surface absent from it cannot be reached,
which is why `term_slug`, `media_filename` and similar are refused rather than
merely unimplemented.

## Provider implementation notes

**WordPress AI Client** — the adapter calls `wp_ai_client_prompt()` and delegates
provider discovery, model selection, credentials and response handling to
WordPress Core. No vendor endpoint, SDK, key or model catalogue is present in
mcLogiora. The Core support check is local and cost-free; generation errors are
returned without exposing raw provider details. The settings status also uses
the local connector registry to distinguish no registered AI provider, an
unconfigured connector, and site-level AI disablement.

**DeepL** — not a language model: no model selection, and the settings screen says
so. Free and Pro keys live on different hosts and are distinguished by the
documented `:fx` suffix. Placeholder protection uses DeepL's own mechanism —
`tag_handling=xml` plus `ignore_tags` naming the shield's tag — so DeepL leaves
protected content alone by contract rather than by luck.

On upgrade to 1.0.2, a one-time migration deletes only the old mcLogiora-owned
credential and model options for the retired AI adapters. It never touches
WordPress Connector options or the separate DeepL credential. Retired PHP
constants are ignored because a plugin cannot unset a constant from
`wp-config.php`.

Keep the transport provider-neutral. Anything provider-shaped outside an adapter is
a bug.

## Security model

- **Capability.** Post surfaces require `edit_post` on the target. Admin surfaces
  require the translation-management capability, plus `edit_term` or `edit_post` on
  the specific object where one applies.
- **Nonce.** Every Generate, Apply and Discard verifies a nonce. The nonce is issued
  only after every readiness gate passes, so an unavailable feature hands the browser
  no usable token and cannot be re-enabled from a console.
- **Server-authoritative source.** The browser sends an object id, a field name and a
  target language. The server resolves the source: through the translation relation
  for posts and terms, by row for strings, from the attachment itself for media.
  Request fields such as `text`, `sourceText`, `source`, `content` and `value` are
  ignored, and tests assert the provider payload contains only the real source.
- **Preview binding.** A preview belongs to one user, object, surface and language.
  Apply uses the stored preview text, never text from the request.
- **Single use.** A successful Apply consumes the token. Discard invalidates it.
- **Cost of rejection.** Every validation failure happens before a provider is
  reached, so a malformed or unauthorised request costs the owner nothing. Tests
  assert zero provider requests on every refusal path.
- **Rollback.** If a status write fails after a field write, the field is restored.
- **No secret leakage.** Credentials never enter localized state or rendered markup;
  the admin shows a masked suffix only. Provider error bodies are normalized before
  display, so no raw response, prompt or internal shield token reaches a user or a
  log.

## Test coverage

| Suite | Scope |
| --- | --- |
| Unit | WordPress AI Client and DeepL adapter behaviour, placeholder shielding, service readiness, status transitions. |
| Integration — editor | Controller security and semantics, editor state secret-absence, Classic surface, apply semantics and rollback. |
| Integration — admin | String, taxonomy and media surfaces: authoritative source, quota-proxy refusal, per-domain status, preview binding, and the media four-field sibling matrix. |
| Integration — builder | Builder payloads still survive translation untouched; `post_content` is never machine-translated. |

Two test doubles support this. `FakeTransport` returns queued responses and records
requests. `EchoTransport` returns the submitted text, which is the only way to
exercise the placeholder round trip: a canned response has already dropped every
shield token, so it can only ever reach the refusal path.

Browser qualification for all six surfaces ran against WordPress 7.1-RC3 with a
deterministic local transport double, and its evidence is kept outside this
repository with the other qualification artefacts.

**Live provider qualification has not been performed.** No request has been made to
WordPress AI Client or DeepL with a real credential.
