# 0019 — Developer & Operations Layer

## Status

Accepted for the layer's shape. Partially implemented: Workstream A is built —
slice 1 the public read API, slice 2 the hook contract review. Workstreams B
through E are not started.

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

### No hook is promoted in slice 1

The plugin fires fourteen hooks. Slice 1 documented all fourteen as an inventory
and promoted none, because promoting a hook means fixing its argument list as a
contract, proving its lifecycle position with a test, and writing down what a
consumer may and may not do with it. Slice 2 does that work; the classification
it reached is below.

No new hook is added in either slice. A hook added "in case someone needs it" is
a contract nobody reviewed.

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

### Hooks are classified by what they let a consumer reach, not by usefulness

The tempting rule for slice 2 was "promote the ones people would want". The rule
actually used is narrower: a hook becomes supported when its arguments expose no
internal type that is not itself ready to freeze, its return semantics are
deterministic, it cannot weaken a security invariant, and it can be proven as a
lifecycle contract by a test. Nine of the fourteen clear that bar.

Two facts made this less of a free choice than it looked. Several of the hooks
were already promised: ADR 0011, ADR 0013, ADR 0015 and ADR 0016 each name one
as *the* extension point for their subsystem, and the plugin's own admin screens
tell site owners to use `mclogiora_widget_adapters` and
`mclogiora_seo_output_open_graph_locale` by name. Refusing to support those
would have withdrawn a promise the product already makes on screen. Conversely,
nothing being already-promised makes a hook safe: `mclogiora_resolved_capability`
is named in `capability-model.md` as an extension point and is still refused.

#### The container hook stays internal

`mclogiora_register_modules` passes `Core\Container` as its second argument.
Supporting it makes every service in the container a permanent compatibility
contract, which would undo the whole point of projecting arrays in slice 1. It
also lets a consumer return a module list with the core modules missing, which
disables the plugin silently rather than loudly. It stays, because removing it
would break anyone already using it, and it stays undocumented as API.

#### The capability hook stays internal, and the decision gets a test

`mclogiora_resolved_capability` is the security boundary. Tracing it found every
admin screen and every write path — translations, menus, widgets, media,
strings, languages, suggestions, the setup wizard — checking whatever it
returns. A callback returning `read` opens all of it to any logged-in
subscriber.

Narrowing it to "may only strengthen" was considered and rejected on the
grounds that it cannot be implemented honestly. WordPress has no capability
ordering: `current_user_can()` is a boolean per capability, and role plugins add
capabilities that no ranking written here could place. A lattice would be a
guess enforcing a security rule, which is worse than no lattice.

What is added instead is a test on the *unfiltered* value: every planned
capability must still resolve to `manage_options` with no consumer attached.
That protects the decision rather than the hook — it fails if the baseline every
screen checks is ever quietly weakened.

#### Two hooks are deferred rather than refused

`mclogiora_register_editors` would freeze `EditorInterface`, which still carries
`get_placeholder_areas()` from the Phase 09 foundation and takes an internal
`EditorContext`. Publishing an interface with a method named "placeholder" in it
commits to keeping the placeholder. `mclogiora_register_settings` passes no
registry and mcLogiora registers nothing through it, so it is currently a
private alias for `admin_init` that consumers already have. Both are recorded as
deferred with the specific thing that has to change first.

`mclogiora_feature_enabled` is refused outright on a factual ground rather than
a design one: nothing calls `FeatureFlags::is_enabled()`, and the flag table
still reports switchers, SEO, builders and external services as absent when all
four shipped. Publishing a filter over it would document a promise that is
already untrue.

#### One production change, and only because the bar required it

`mclogiora_register_payload_adapters` was invoked inside an anonymous factory in
`Application`, behind a container entry that caches its result. There was no way
to exercise the filter in a test without restructuring, and a hook that cannot be
tested cannot meet the promotion bar. Its construction moved to
`PayloadAdapterRegistry::with_core_adapters()`, a named factory mirroring the one
`WidgetAdapterRegistry` already had. Behaviour is unchanged; the two registries
are now built the same way, and the filter is reachable.

That is the only production change slice 2 makes. Every other promoted hook is
promoted where it already stood.

#### `SeoSubject` is narrowed contractually rather than structurally

The canonical and x-default filters pass a `SeoSubject`. Adding a parallel array
argument was considered and rejected: slice 1 refused a second name for the
switcher template tag on sprawl grounds, and a second representation of the same
subject is the same mistake. Instead the contract names four methods —
`kind()`, `object_id()`, `taxonomy()`, `is_home()` — and states that the class
itself is internal. The promoted surface is a named subset of an object, not the
object.

## Consequences

- Themes and plugins can read languages, translation relations and translated
  URLs through a surface that is documented, tested, and safe to call before the
  plugin boots.
- The internals stay free to change. Repositories, value objects, the container
  and the schema are named in the documentation as explicitly not public.
- Workstreams B through E each have one resolver to wrap.
- Nine hooks are supported contracts with documented arguments, documented
  return semantics, and a lifecycle test each. Five are recorded as unsupported
  with the specific reason, so finding one in the source does not read as a
  promise.
- Extending mcLogiora is possible without reaching into the container: widgets
  and builder payloads have supported registration filters, SEO output has
  supported overrides, and activation has a supported lifecycle hook.
- Authorisation cannot be weakened through a documented extension point, and the
  unfiltered capability baseline is now pinned by a test.
- A site with no consumer of the API behaves exactly as it did in 0.15.0. The
  bootstrap gains one `require_once` of a file that defines functions and
  registers nothing, and slice 2 adds documentation, tests, and one
  behaviour-preserving move of a registry factory.

## Deliberately not decided here

- Which release version carries Phase 17. The sources use `@since x.x.x`.
- Whether third parties may register suggestion providers. That question belongs
  with workstream A's hook review, and ADR 0018's constraints — explicit
  configuration, no arbitrary endpoint — bind whatever it concludes.
- Raising `Tested up to` beyond 7.0, which remains its own compatibility gate.
