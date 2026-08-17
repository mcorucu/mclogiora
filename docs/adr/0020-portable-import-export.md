# 0020 — Portable Import / Export

## Status

Accepted for the package contract and the read/apply side. Implemented in
Phase 17, workstream D, slices 1–3: package format, export, parsing,
validation, dry-run planning, immutable-plan apply, stale protection, atomicity,
rollback, persistent-cache-safe invalidation and final verification. The
authoritative plan requires no REST, CLI or admin transport for closure.

ADR 0019 remains the umbrella for the Phase 17 developer and operations layer
and names workstream D; this ADR records the decisions that layer could not
make in advance.

## Context

Two authoritative requirements meet here. Section 8 of the planning document
asks for "portable JSON/CSV packages, language filters, dry-run validation", and
section 17 lists "import validation with dry-run before write operations" among
the plugin's security requirements — not as a nicety, but beside prepared SQL
and capability checks.

That second placement is the one that shapes everything. A dry run listed under
security is not a preview feature; it is the statement that a write path may not
exist without an inspection path in front of it. So the order of work is fixed
before any design question is asked: the package, the reader and the plan come
first, and the writer comes after, consuming what the plan produced.

Three pressures pull the wrong way.

**Fidelity pulls toward exporting the database.** The highest-fidelity export of
a site's translation state is its rows. It is also unusable anywhere except the
machine it came from, carries table prefixes and auto-increment ids, and would
have to be excluded field by field from ever containing an API key.

**Convenience pulls toward exporting object ids.** The relation table already
names posts and terms by id, and copying that column is one line. It produces a
package that imports into the wrong content on any site but its own — and, worse,
one that appears to work.

**Symmetry pulls toward writing the apply at the same time.** Export and import
look like one feature. Building both together is how the dry run ends up as a
second, approximate implementation of a write path that was designed first.

## Decision

### A portable domain package, not a backup

The package describes mcLogiora's own state — configured languages, and which
objects are translations of which — and nothing else. It is not a backup, a SQL
dump, a table copy, a serialized object graph, or a WordPress content export. It
carries no post body, no meta, no media file, and no provider credential.

That is what makes it safe to hand to a person. A package is meant to be opened,
read, diffed, attached to a ticket and committed to a repository, and it is
built so that doing any of those leaks nothing.

### JSON, with fixed encoding flags

An operator approving an import has to be able to check the plan against the
file. `serialize()` would be shorter and would tie every package to PHP class
names that are explicitly not public API; igbinary, a SQL dump and WXR are each
unreadable for this purpose, and WXR describes posts, which is a different
subject.

Encoding flags are fixed by the format rather than chosen per caller. Two
transports encoding the same state into two different byte strings would make
packages incomparable, and comparing packages is how determinism is proven.

### The format version is not the plugin version

`format_version` is an integer, currently `1`, and is never derived from
`MCLOGIORA_VERSION`.

A release that changes nothing about serialization must not invalidate every
package a site has already taken, and two plugin versions that both speak format
1 produce interchangeable files. An unsupported format version is refused; a
differing *plugin* version is a warning and never a refusal. Format
compatibility is the authority on whether a package can be read.

### The manifest records nothing about the source site

No site URL, no site name, no administrator address, no user, no environment, no
path. None of it is needed to read or apply a package, and a file that travels
between sites and sits in backups is the worst available place to record who
produced it.

The manifest's `counts` are checked against the payload, and that check is
described in the documentation as a truncation check and nothing more. mcLogiora
does not sign packages, so it writes no checksum: an unsigned digest sitting next
to the data it digests invites exactly the authenticity reading it cannot
support.

### Group identity is the existing UUID

The portable group identity is the group UUID mcLogiora already assigns. That is
what the UUID column has always been for; the auto-increment integer beside it is
where the row happens to live.

Carrying it across sites makes an import idempotent — a second import of the same
package finds the group the first one created rather than building a duplicate —
and no second group identity format is introduced.

### No package contains a post id or a term id

Objects are named by a locator: post type plus slug, plus the ancestor slug path
inside hierarchical post types; or taxonomy plus slug for terms. The destination
resolves the locator against its own content.

Ids are omitted entirely rather than carried "for reference". A field that exists
is a field a later apply can be tempted to trust, and the whole point is that
post 41 on two sites is two unrelated things.

The ancestor path is part of the address because WordPress keeps a slug unique
per parent inside a hierarchical type. It is compared exactly: a page moved to a
different parent is a different address, and adopting it because the last segment
still matches would silently relink content.

### Unresolvable is a first-class outcome

A locator can name nothing, and it can name several things. Both are reported by
name, with every match listed, and neither is resolved by picking one. Taking the
lowest id would be arbitrary, would look like it worked, and would attach a
translation to whichever page happened to be created first.

Two further outcomes are distinguished rather than folded into "not found": a
locator with no slug — WordPress leaves a draft without one until it is
published, and mcLogiora creates translations as drafts — and a post type or
taxonomy the destination does not register. Both are facts about the package or
the site rather than about the content, and an operator can act on them only if
they are told which one they are looking at.

### Import is additive, and says so

The plan creates languages the destination lacks, creates groups it lacks, and
links objects that are not yet linked. It never overwrites: not a language's
metadata, not a translation's status, not an occupied language slot, not the
site's default language. Every disagreement about something that already exists
is reported as a conflict and planned for not at all.

This is a decision, not a missing feature. An overwrite policy has to say which
side wins, per field, per domain, and no authoritative source has one. Inventing
one inside a planner would mean the first site to import a package discovers the
policy by losing work to it. There is consequently no `update_language` and no
`update_status` operation in format version 1's plan vocabulary.

Where the package's status differs from the destination's, the plan asks
`TranslationStatusTransitions` whether the move would even be legal and records
the answer alongside both statuses. Restating that matrix inside the import path
would make it a second, quieter status lifecycle.

### The dry run is the plan apply will execute

`ImportPlanner` produces an `ImportPlan` carrying resolved destination
identifiers. Slice 2 executes that list. It does not re-read the package and
decide again.

This is the load-bearing structural decision of the slice. Two implementations
of "what should happen" drift, and when they drift the operator has approved the
older one. The plan is immutable and inert: every accessor returns state the
planner already computed, so reading a plan cannot change it and cannot touch
the site.

Building a plan performs zero inserts, zero updates and zero deletes. That is
asserted against real WordPress by snapshotting every mcLogiora table plus posts,
postmeta, terms and `mclogiora%` options around repeated planning runs, including
against a destination full of conflicts.

### Apply is an exact, stale-protected transaction

`ImportApplyService::apply()` accepts an `ImportPlan`, not raw package data. It
does not call `ImportPlanner` and it does not resolve a locator again. Before
opening a transaction it checks capability, refuses errors/conflicts/unresolved
references, and compares every operation with the language, group, slot,
object-assignment and locator state observed by planning. A mismatch returns
the stable `import_plan_stale` issue and performs zero writes.

The operation executor calls the language and relation domain services in the
plan's order. Group creation has an explicit-key domain path so the package
UUID is preserved. Target status is validated as a legal initial status and is
passed at item creation; no existing status is changed and no transition
matrix is duplicated in import.

All writes in this slice are to plugin-owned relational tables and share one
database transaction. There are no WordPress post or term writes to roll back.
Operation failure, late invariant failure, verification failure or commit
failure rolls back and returns a structured result with stable issue codes,
counts and rollback status. Final verification checks exact language fields,
group/source identity, link status, skip equivalence and locator identity
before commit. Tests inject failures through an executor double; production
has no debug failure flag.

### Parsing treats input as hostile, and never deserializes objects

`unserialize()` appears nowhere in this layer and must not. PHP object
deserialization on untrusted input is remote code execution with extra steps.

The parser decides shape and type only, and hands the result to a validator that
asks the different question of whether the package means anything on this site.
Keeping the two apart lets a package be checked before a destination is chosen,
and stops a validator becoming a second reader that can disagree with the first.

Unknown keys *inside* objects are ignored, so a later 1.x producer that adds an
optional field still reads here. Unknown *sections* and unknown top-level members
are refused: the first would mean silently dropping a whole domain of data while
reporting a complete plan, and a plan that omits data without saying so is worse
than a refusal.

### The scope of format version 1 is languages and relations

Strings, media metadata, menus, widgets and settings are excluded from this
slice — each because it is a separate portability problem, and each recorded as
such in `docs/architecture/import-export.md` rather than left to be inferred.

Settings deserve the specific note: `url_strategy` reshapes every permalink on
the destination, so a settings section needs a per-setting audit of what is
portable. Exporting the option row because it exists is how a package acquires
scope nobody reviewed.

### Nothing in this layer is a transport

The exporter, parser, validator, planner and apply service are application
services registered in the container and hooked to nothing. No REST route, no
WP-CLI command, no admin screen, no upload handler is required by the
authoritative Workstream D scope. A site that never imports anything runs no
extra code. Any future transport is a separate decision and must consume this
service instead of growing package interpretation inside a controller.

### Rollback cache coherence

Rollback deletes `languages_all`, `translation_groups_all` and the stable
per-group cache keys named by the plan through `CacheInterface`. The WordPress
implementation delegates to `wp_cache_delete()` in the mcLogiora cache group,
so persistent object-cache entries are removed as well as current-process
values. Global `wp_cache_flush()` is deliberately not used: unrelated cache
families must survive an import rollback. The locator gateway disables
post/term cache priming and relation item lookup has no separate cache, so the
targeted language and relation families cover all uncommitted apply state.

## Consequences

- A site can produce a package, read one, see exactly what importing it would
  do, and apply only that reviewed plan. The security requirement in section 17
  is met by construction rather than by discipline.
- The plan is a reviewable artefact with stable machine codes, so a later
  transport can react to a specific conflict without matching translated prose.
- Packages are portable between sites, and honest about where portability ends.
  A draft with no slug yet, an object deleted underneath its relation, and a post
  type the destination does not register are each reported by name.
- Slice 2 has one job — execute an operation list atomically and roll back — and
  no room to reinterpret the package.
- Exporting a site whose translations are mostly unpublished drafts produces a
  package whose targets cannot be resolved anywhere. That is a real limitation of
  addressing content by slug, it is visible in the plan rather than hidden, and
  it is the reason a later slice may need a second locator strategy rather than a
  looser one.
- `TranslationRelationRepositoryInterface` gained `active_group_keys()`. The
  existing `all()` is capped at 100 rows and ordered by recent activity, which is
  right for a dashboard and wrong for anything that must see every group twice in
  the same order.
