# 0019 — Developer & Operations Layer

## Status

Accepted for the layer's shape. Partially implemented: Workstream A is built —
slice 1 the public read API, slice 2 the hook contract review. Workstream B is
complete for the translation domain: slice 1 the read surface, slice 2 status
transitions, slice 3 relation membership, slice 4A content creation, slice 4B
taxonomy creation. All seven domain mutations are reachable over HTTP.
Workstream C is complete: slice 1 the read-only commands, slice 2 the relation
and status mutations, slice 3 creation. Every translation-domain operation is
reachable from both HTTP and the shell. Workstream D is complete: slices 1–3
deliver the portable package, export, parse/validate/dry-run, immutable-plan
apply, stale protection, atomicity, rollback, persistent-cache-safe invalidation
and final qualification. The authoritative planning audit requires no operator
transport for D closure. Workstream E is not started.

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

### REST is a projection of the read API, not a second domain layer

Workstream B's first slice registers three GET routes under `mclogiora/v1`, the
namespace and vocabulary the plan already fixed. Every handler reads through
`Api\PublicApi` — the same functions a theme calls — and none touches a
repository. That is the whole reason A came first: a controller that queried
storage directly would become a second definition of what a translation is, and
the two would eventually disagree about which one HTTP should believe.

Responses are rebuilt field by field rather than handed the reader's array and
passed to `rest_ensure_response()`. Passing it through would publish, on the day
it is added, any field a future projection gains — without anyone deciding to
publish it. No domain object is ever serialized; the JSON contains scalars,
nulls and arrays of them.

Translated URLs are asked of `TranslatedUrlGenerator` through the read API
rather than assembled from parts in a controller, for the reason it has always
been the only URL authority.

#### Read and write are separate slices on purpose

No write method exists on any route, and no stub returns "not implemented" —
`POST` to a mcLogiora path is a 404 from WordPress. Writes must go through
`TranslationWorkflowService`, which checks capability inside each method as well
as at the request boundary, and wiring that up is its own slice with its own
security argument. Shipping reads first means the projection, the permission
boundary and the error vocabulary are settled and tested before anything can
change state through them.

#### The permission split is per route, and the relation routes are closed

`/languages` serves its active set publicly. Every field it returns is already
published by the language switcher on any page carrying one, and by the
`hreflang` block on pages carrying none; refusing it over HTTP would be theatre.
`status=all` adds languages that are configured but not enabled — unpublished
configuration nothing on the front end reveals — and is gated.

`/relations` and `/translations` require the capability to manage translations.
A relation record names object IDs whatever state those objects are in, the
relation layer has never filtered by post status or by reader, and the plan
forbids exposing private post data or unpublished translation content to
unauthorised users. A per-object public projection is buildable but needs
correct read authorisation for six object types; that is deferred rather than
guessed at, and a test asserts a private translation's ID never reaches an
anonymous or subscriber caller.

Permission is checked before the lookup, so a refused caller cannot tell a
missing object from a forbidden one and probe for existence.

#### One mutation family, chosen for what it cannot touch

`TranslationWorkflowService` and its two sub-workflows expose seven mutations:
create, link and unlink for posts, the same three for terms, and the status
change. Slice 2 exposes exactly one of them.

Status transitions were chosen over the other six for a reason that is not
convenience. They are the only mutation that creates and destroys nothing: no
post, no term, no content, no slug, no revision. That makes the blast-radius
claim provable exactly rather than approximately — the tests assert a full post
fingerprint and every relation row count is unchanged, which is an assertion the
create and link operations could not make. Status changes are also the only
mutation on the facade itself, and the only one that is object-type generic, so
one route serves posts and terms where every other operation would need two.

The remaining six are deferred with that stated, not left unmentioned.

#### The controller decides nothing

The handler maps HTTP arguments to one `change_status()` call and projects the
result through the Slice 1 item projection. It does not check whether a
transition is legal, whether the source item may change status, or whether the
caller may manage translations. Restating any of those would create a second
rulebook that eventually disagrees with the admin screens — the failure ADR 0010
pre-empted by requiring REST to wrap the workflow. Nothing in the REST layer
writes through a repository or `$wpdb`.

The `status` argument's `enum` is the status *vocabulary*, not the transition
rule, and it deliberately still contains `original` and `missing`. Excluding
them would have REST answering a domain question with a generic
`rest_invalid_param` instead of letting the workflow refuse with the precise
reason those two are not assignable.

#### Domain codes pass through; the status is REST's decision

A refusal keeps the workflow's own error code so the same refusal is
identifiable across REST, the admin screens and a future CLI. What REST adds is
the HTTP status, and the split is deliberate: 400 when the request was wrong
whatever the state, 409 when it was well formed and conflicts with the state
this translation is in. Flattening both into one code would make
retry-after-fixing-the-request indistinguishable from
retry-after-the-state-changes. Defaulting the unmapped cases to 409 rather than
500 follows from what they are — refusals, not failures.

#### No second nonce

Writes require no mcLogiora nonce. A cookie-authenticated REST request already
needs `X-WP-Nonce`, enforced by WordPress before any route runs. Adding an
admin-form nonce because the admin UI uses one would have copied a transport's
security into a transport that already has its own, and would have made
Application Password clients unable to call the route.

#### Membership is a resource; the post is not

Slice 3 adds `POST` and `DELETE` on `/relations` for the domain's four
link-and-unlink operations, two for posts and two for terms.

The route choice carries the whole argument. `DELETE /relations` deletes a
*membership*. Had these operations been hung off a content path, the same verb
would have read as "delete this post", and the gap between what a verb appears
to mean and what it does is precisely where someone loses content. Under
`/relations` the resource is unambiguous, and the response says `detached` rather
than anything resembling `deleted`. Deleting content stays WordPress's own job
and is not reachable from this namespace at all.

That distinction is asserted, not merely documented: an unlink is followed by a
full post fingerprint comparison — type, title, excerpt, content, slug, status,
parent, author, both dates — plus revision count and a total post count, and
the same for terms across name, description, slug, parent and taxonomy. A link
is held to the same standard in the other direction: it creates nothing and
edits nothing.

#### One transport, two domain paths

Posts and terms share the route and the argument shape but not a code path. The
controller branches once on `object_type` and calls
`ContentTranslationWorkflow` or `TaxonomyTranslationWorkflow`. Collapsing them
into a single generic relation write would have been less code and would have
discarded exactly the checks that differ: post type against post type, taxonomy
against taxonomy. Those are what stop a category becoming the translation of a
page.

The object-type enum on these routes is `post` and `term` only. That is not REST
narrowing the domain's vocabulary; it is REST declaring which of the domain's
operations exist. The other five relation content types have no link workflow to
call.

#### Object permission is not the capability check

These operations surfaced something the status slice did not: the workflows
apply per-object checks — `edit_post` on both the source and the target, and
`manage_categories` for terms — after the general capability has passed. REST
maps those refusals to 403 and adds nothing of its own. A caller who may manage
translations in general can still be refused for one particular post, which is
the correct answer and not one a permission callback could have given.

#### create_translation stays deferred

It is the one remaining mutation, and the only one that creates a real
WordPress object. Everything shipped so far can be described by what it does not
touch; that one cannot, and it deserves the slice it did not get here.

#### Content creation is its own risk slice

`create_translation` was held back from slices 2 and 3 and given its own,
covering posts only. Every earlier REST write could be described by what it does
not touch; this one creates a WordPress post, so it has to be described by what
it does exactly. Terms wait for a further slice rather than riding along on a
shared method name.

The route is `POST /translations`, and that forced a correction. Slice 2 had
registered `EDITABLE` there, so `POST` meant "change a status". On a collection
`POST` means create, and one verb meaning both would have been resolved by
inspecting which parameters happened to arrive — a design that fails quietly.
The status change narrowed to `PUT|PATCH`. Nothing was released, so this is a
correction before release rather than a break, and the resource model is now
consistent: `POST /relations` adds membership for an object that exists,
`POST /translations` creates the object.

The route accepts three fields and no WordPress post field at all. Title,
content, status, author, parent, slug, meta and terms are the workflow's to
decide. A route that accepted them would be a content-creation endpoint wearing
a translation label, and the draft-only guarantee below would be one parameter
away from being untrue.

Two properties are asserted rather than assumed. The new post is always a
`draft`: a translation nobody has read must not go live because a client asked
for one. And a repeat creates nothing — the language-slot check runs before the
insert, so a refused request costs no post rather than creating one that is then
rolled back.

#### Terms got their own slice, and it earned it

Taxonomy creation shares a route and a method name with content creation and
almost nothing else, which is why it was held back rather than folded in.
Qualifying it against real WordPress corrected two assumptions that reading the
code had not.

The first was collision behaviour. The workflow supplies a provisional
language-scoped slug — `sanitize_title( "{name}-{language}" )` — precisely so a
translation does not collide with its source when both names reduce to the same
slug. A consequence is that `wp_insert_term` does *not* treat a matching name as
a duplicate, and when the derived slug is itself taken WordPress suffixes rather
than refusing. Both cases were expected to be refusals and are not.

That makes the adoption boundary more important, not less: creation succeeds
where it might have failed, so what matters is that it creates. A term with the
same name, or one already holding the wanted slug, is never handed back as the
translation. There is no fallback to `link_existing` — that is a different
operation with its own route and the caller must choose it. Both cases are now
pinned by tests asserting a distinct new term id and an untouched original.

The second was the parent rule, which is a real invariant rather than a default:
the translated term takes its parent only when the source's parent is already
translated *into the same language*, and `0` otherwise. mcLogiora will build a
flat hierarchy before it builds a mixed-language one. All three states — no
parent, untranslated parent, parent translated into another language — are
tested, along with the non-hierarchical case.

#### The rollback existed and was untested

The workflow has compensated for post-create failures since Phase 14, with two
paths and a comment explaining each. Neither had a test at the content layer.
That is the state in which a guarantee quietly stops being true, so the
regression was added at the domain layer where the guarantee lives, injecting
the failure through
`mclogiora_register_payload_adapters` — the plugin's own supported extension
point — so nothing is stubbed and no production code is altered to make the test
possible. A site whose builder adapter fails is exactly the modelled situation.

The taxonomy path was better off: a unit test already proved the workflow
*calls* `delete_term` against a fake gateway. What it could not show is that
WordPress then actually removes the term, so an integration regression was added
using `created_term`, a core hook firing inside `wp_insert_term` — after the term
exists, before the relation is written. Occupying the target slot at that instant
is the real race the compensation exists for: free when checked, taken when
written.

The test found the guarantee holds: the draft is removed and no relation record
outlives the object it pointed at. It also clarified the boundary. The
translation *group* survives holding only its source, because
`resolve_or_create_group()` runs before the insert and the slot-free check needs
it. That is the same state a group reaches when its last translation is
unlinked, so it is recorded as the domain's behaviour rather than treated as a
leak.

REST contains no compensation code. A second implementation would eventually
disagree with the first about what cleaning up means.

#### WP-CLI is a third transport, not a third rulebook

The commands read through `PublicApi` and publish the same field names REST
does. Having shell access means a command *could* reach a repository directly,
and that is precisely why it must not: an operator comparing `wp mclogiora
relation get` against `GET /relations` must not find two answers, and
`native_name` must not become `label` because a table looked nicer that way.

Registration is gated on `RuntimeReadiness::is_cli()`, the same authority every
other module asks about the request, so a web request constructs nothing. The
command classes deliberately do not extend `WP_CLI_Command` and mcLogiora takes
no Composer dependency on WP-CLI: the runtime provides it when it is the
runtime, and no file in `src/` names a WP-CLI class that a site without WP-CLI
would fail to load. Output goes through WP-CLI's own `Formatter` rather than a
renderer of ours.

#### CLI does not inherit REST's permission model

Two decisions differ from REST on purpose, because the execution model differs.

`wp mclogiora language list` defaults to **all** configured languages, where
REST defaults to active and gates the rest behind a capability. REST is gated
because anonymous HTTP callers exist. Running `wp` means shell access to the
server, which is already more privileged than any WordPress role, so copying
that default would hide configured-but-disabled languages from the person
administering them.

Relation inspection likewise returns object IDs for drafts and private posts.
An operator who can run `wp` can read the database directly; withholding an ID
from them would be theatre rather than security. What stays out is what stays
out everywhere: credentials, preview tokens, source hashes, table names, class
names.

#### Read and write are separated again

No command mutates anything. Qualifying that took running the real binary
against a real installation — fifteen command invocations left every row count,
every relation hash and every `post_modified_gmt` byte-identical, and made zero
outbound requests. A PHPUnit suite could not have shown it: WP-CLI's classes are
present in the harness as a development dependency, but nothing dispatches a
command, so the argument parsing and projections are tested there and the
dispatch, formatting and exit codes are qualified by running `wp mclogiora …`
on both WordPress builds.

#### Creation closes the CLI, and it still owns no rules

Slice 3 adds `translation create` for posts and terms, dispatching to the same
two workflows REST calls. With it, all seven translation-domain mutations plus
the reads exist on both transports.

The command takes three arguments and, for terms, a taxonomy, a name and an
optional description — exactly the workflows' own inputs. There is no `--title`,
`--status`, `--slug`, `--parent` or `--meta`, and adding any of them would have
turned a translation command into a clone command with a translation record
attached. The draft-only guarantee for posts and the provisional-slug and
same-language-parent rules for terms are the workflows', unchanged.

`create` creates. It never adopts a term that already exists, even when the name
matches or the wanted slug is taken; that is what `relation link` is for, and the
help says so. A CLI that quietly fell back to linking would make two different
operations indistinguishable from the outside.

The workflow also returns an edit link. REST drops it and so does the CLI: one
published vocabulary across transports is worth more than a convenience field on
one of them.

Rollback stays where it was proven. The CLI contains no compensation logic, and
a test strips comments from every file in `src/Cli` before asserting that none of
them names `wp_insert_post`, `wp_delete_post`, `wp_insert_term`, `wp_delete_term`
or `wpdb` — the first version of that test failed on a docblock explaining the
rule, which is a fair reminder that an audit should read code.

#### What Workstream C deliberately does not include

The authoritative scope is `wp mclogiora …` wrapping the workflow services, and
that is now met. Import and export belong to workstream D, status and
diagnostics to workstream E, and suggestions stay off every programmatic
transport for the reason recorded under REST: ADR 0018 requires an explicit
human action per suggestion, and a command would make bulk machine translation
trivially scriptable. Language configuration, strings, media and settings have
no CLI requirement in any authoritative source, and inventing commands because
they would be convenient is how a transport acquires scope nobody asked for.

#### Declared arguments only constrain if a validate_callback says so

Building this slice surfaced a WordPress detail worth recording:
`register_rest_route()` installs no default `validate_callback`, and
`WP_REST_Request::has_valid_params()` calls only what is registered. An `enum`
or a `minimum` written beside a hand-authored argument therefore enforces
nothing on its own — it reads as a constraint and behaves as a comment. The
first version of this slice had exactly that bug, and a test caught it. Every
argument now names its callback explicitly.

## Consequences

- Themes and plugins can read languages, translation relations and translated
  URLs through a surface that is documented, tested, and safe to call before the
  plugin boots.
- The internals stay free to change. Repositories, value objects, the container
  and the schema are named in the documentation as explicitly not public.
- Workstreams B through E each have one resolver to wrap, and B now demonstrates
  that wrapping it is enough: three HTTP routes added no domain logic.
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
