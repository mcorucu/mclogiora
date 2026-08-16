# 0019 — Developer & Operations Layer

## Status

Accepted for the layer's shape. Partially implemented: Workstream A slice 1
(the public read API) is built; workstreams B through E are not started.

## Context

Sixteen phases built a multilingual platform with no supported way to ask it a
question from outside. That is not an oversight. Phase 05 wrote down that
developer APIs stay internal, and every phase since deferred the question rather
than answering it halfway: ADR 0016 rejected a REST endpoint for the editor
because "Phase 17 owns the public API question", and ADR 0010 recorded that when
REST and WP-CLI arrive they must wrap `TranslationWorkflowService` rather than
restate its rules.

The result is that the plugin currently exposes three template tags, eleven
filters and three actions, none of which was designed as a contract, plus a
large amount of `public` PHP that is public only because PHP has no better word
for it.

Two pressures pull the wrong way here.

**Breadth pulls toward publishing what already exists.** Every repository method
is already callable. Declaring the existing surface "the API" would be a day's
work and would freeze the internals of a plugin that still has two phases to go
— including the source-change hashes, whose meaning has already changed once
between phases.

**Symmetry pulls toward building all five surfaces at once.** REST, CLI,
import/export and System Status all read the same data, and it is tempting to
write them together. They also all need one resolver, and writing four callers
before the thing they call is how two of them end up with their own copy of the
rules.

## Decision

### Five workstreams, ordered, not parallel

Phase 17 decomposes into five:

| | Workstream | Delivers |
| --- | --- | --- |
| A | Developer Extension API | Public read functions, then a reviewed hook contract |
| B | REST API | `/mclogiora/v1/…` under permission callbacks |
| C | WP-CLI | `wp mclogiora …`, wrapping the workflow services |
| D | Import / Export | Portable packages with a dry run before any write |
| E | Diagnostics | System Status screen and Site Health integration |

A comes first, and not merely by preference. The planning document states that
mcLogiora provides a stable developer API *before* encouraging third-party
extensions, and B through E are each a consumer of A's resolver. Building them
first would produce four independent answers to "what languages does this site
have", which is exactly the failure `LanguageContextInterface` and
`TranslatedUrlGenerator` were introduced to prevent elsewhere.

### The public surface is functions returning arrays

The published API is `mclogiora_`-prefixed functions returning plain arrays and
scalars. No domain object crosses the boundary.

Handing a caller a `Language` publishes every method on it. Handing them a
`TranslationGroup` publishes `TranslationItem`, and with it `source_hash()`,
`translated_source_hash()` and both modified timestamps — the change detector's
private working state, which two phases have already reshaped. Projection to an
array costs a few lines per type and buys the freedom to keep changing what is
behind it.

The projections drop what callers should not depend on: the `LanguageStatus`
constants become `is_active`, and the source-change fields are simply absent.

### Read-only first, and read-only means no authorisation either

Slice 1 publishes six readers and nothing that writes. That is what makes it
safe to ship in one session: there is no new nonce, no new capability check, no
new mutation path, and therefore nothing new that can be got wrong.

It also fixes a boundary that must be stated rather than assumed. These readers
return relation records. They do not filter by post status and they do not
consult the current user, because the relation layer never has — the language
switcher has behaved this way since Phase 12. A returned object ID is therefore
a fact about the relation graph, not permission to display that object, and the
documentation says so in those words. Making the readers authorise would be a
second, quieter authorisation system disagreeing with WordPress's own.

Programmatic writes belong in workstream C, behind
`TranslationWorkflowService`, which already checks capability inside each method
as well as at the request boundary.

### URL generation is delegated, never reimplemented

`mclogiora_get_language_url()` calls `TranslatedUrlGenerator` and returns what
it returns, including `null`. The generator is the only place that decides what
a translated URL looks like; a second implementation would drift and start
handing themes links that do not resolve. An integration test asserts the API
and the generator return the identical string for the same object, so the
delegation cannot quietly become a copy.

`null` propagates unchanged. Substituting the language home page for a missing
translation would be a friendlier-looking API and a worse one: it would send
readers to content that is not the thing they clicked.

### No hook is promoted in this slice

The plugin fires fourteen hooks. All fourteen are documented in
`docs/architecture/developer-api.md` as an inventory, and none is promoted to
public.

Promoting a hook means fixing its argument list as a contract, proving its
lifecycle position with a test, and writing down what a consumer may and may not
do with it. That is real work per hook, and two of the fourteen sit directly on
security invariants: `mclogiora_register_modules` hands out the service
container, and `mclogiora_resolved_capability` decides which WordPress
capability every admin screen checks. Publishing those as convenience seams
would make it possible to weaken authorisation through a filter.

No new hook is added either. A hook added "in case someone needs it" is a
contract nobody reviewed.

### The template tags that already shipped are recognised, not renamed

`mclogiora_language_switcher()`, `mclogiora_the_language_switcher()` and
`mclogiora_current_language()` have shipped since 0.11.0. They are documented as
supported.

The planning document sketched `mclogiora_render_language_switcher()` and
`mclogiora_get_current_language()`. The first is not added: a second name for a
function that already ships is sprawl. The second is added, because it answers a
different question — the whole language record rather than the code — and an
integration test asserts the two can never disagree about which language that
is.

## Consequences

- Themes and plugins can read languages, translation relations and translated
  URLs through a surface that is documented, tested, and safe to call before the
  plugin boots.
- The internals stay free to change. Repositories, value objects, the container
  and the schema are named in the documentation as explicitly not public.
- Workstreams B through E each have one resolver to wrap.
- The hook inventory is written down, which is the prerequisite for reviewing it
  later, without any of it becoming load-bearing in the meantime.
- A site with no consumer of the API behaves exactly as it did in 0.15.0. The
  bootstrap gains one `require_once` of a file that defines functions and
  registers nothing.

## Deliberately not decided here

- Which release version carries Phase 17. The sources use `@since x.x.x`.
- Whether third parties may register suggestion providers. That question belongs
  with workstream A's hook review, and ADR 0018's constraints — explicit
  configuration, no arbitrary endpoint — bind whatever it concludes.
- Raising `Tested up to` beyond 7.0, which remains its own compatibility gate.
