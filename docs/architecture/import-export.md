# Portable Import / Export

This document describes the portable package mcLogiora writes and reads, and the
import path that inspects one without applying it.

Status as of Phase 17, workstream D, slice 1: a site can **produce** a package,
**read** one, **validate** one against itself, and **plan** what importing it
would do. Nothing applies a plan. There is no code in the plugin that writes a
package's contents into a site, and no flag that turns the planner into one.

## 1. What a package is

A package is a portable description of mcLogiora's own translation state: which
languages a site is configured for, and which of its objects are translations of
which others.

It is deliberately **not**:

- a database backup or a restore point,
- a SQL dump or a table copy,
- a serialized PHP object graph,
- a WordPress content export (it contains no post body, no meta, no media file),
- a settings dump, and in particular carries no provider credential of any kind.

The distinction matters because it decides what a package can safely be given
to. A backup can only be restored onto the machine it came from. A package is
meant to be attached to a support ticket, committed to a repository, or read by
a person, and it is built so that doing any of those leaks nothing.

## 2. Format

JSON, UTF-8, one object per file.

JSON was chosen because a package has to be inspectable. An operator approving
an import needs to be able to open the file and check the plan against it, and
`serialize()`, igbinary, a SQL dump or WXR are all unreadable for that purpose —
`serialize()` additionally ties every package to PHP class names that are
explicitly not public API.

Encoding flags are fixed by `PackageFormat::json_flags()`:
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, plus `JSON_PRETTY_PRINT` when
a caller asks for indentation. Fixing them is what makes two exports comparable;
pretty printing changes whitespace and nothing else.

### Format version

The package carries `format_version`, an integer, currently `1`.

It is **not** the plugin version and must never be set from it. A release that
changes nothing about serialization must not invalidate every package a site has
already taken, and two different plugin versions that both speak format 1
produce interchangeable files. `0.15.0` is not a serialization schema.

A package whose `format_version` this build does not support is refused with
`mclogiora_package_unsupported_version`. A package whose *plugin* version differs
is never refused — it produces the warning `plugin_version_differs` and is read
normally. Format compatibility is the authority.

## 3. Envelope

```json
{
  "manifest": {
    "format": "mclogiora/translation-package",
    "format_version": 1,
    "generator": "mclogiora",
    "generator_version": "0.15.0",
    "created_at": "2026-01-01T00:00:00Z",
    "sections": ["languages", "relations"],
    "counts": { "languages": 2, "relation_groups": 1, "relation_items": 2 }
  },
  "payload": {
    "languages": [
      {
        "code": "en",
        "locale": "en_US",
        "native_name": "English",
        "english_name": "English",
        "direction": "ltr",
        "is_active": true,
        "is_default": true,
        "order": 0
      },
      {
        "code": "tr",
        "locale": "tr_TR",
        "native_name": "Türkçe",
        "english_name": "Turkish",
        "direction": "ltr",
        "is_active": true,
        "is_default": false,
        "order": 1
      }
    ],
    "relations": [
      {
        "group_key": "00000000-0000-4000-8000-000000000000",
        "items": [
          {
            "object_type": "post",
            "language": "en",
            "status": "original",
            "is_source": true,
            "locator": {
              "kind": "post",
              "post_type": "page",
              "slug": "about",
              "ancestors": ["company"]
            }
          },
          {
            "object_type": "post",
            "language": "tr",
            "status": "translated",
            "is_source": false,
            "locator": {
              "kind": "post",
              "post_type": "page",
              "slug": "hakkimizda",
              "ancestors": ["sirket"]
            }
          }
        ]
      }
    ]
  }
}
```

The example is synthetic. Group keys on a real site are UUIDs.

### Manifest

| Field | Meaning |
| --- | --- |
| `format` | Always `mclogiora/translation-package`. A file that does not declare it is refused. |
| `format_version` | Integer wire version. See above. |
| `generator` | Producing plugin slug. A foreign generator is a warning, not a refusal. |
| `generator_version` | Producing plugin version. Informational. |
| `created_at` | ISO 8601 in UTC. The only field that differs between two exports of unchanged state. |
| `sections` | Section names present in the payload. |
| `counts` | `languages`, `relation_groups`, `relation_items`. |

**The manifest records nothing about the source site.** No site URL, no site
name, no administrator address, no user, no environment, no path. None of it is
needed to read or apply a package, and a file that travels between sites and
sits in backups is the wrong place to record who produced it.

`counts` is checked against the payload and a disagreement is refused with
`mclogiora_package_count_mismatch`. That is a **truncation check only**. Anyone
who can edit a payload can edit the counts beside it, so it says nothing about
authenticity. mcLogiora does not sign packages and does not imply that it does;
no checksum is written, because an unsigned digest next to the data it digests
invites exactly that misreading.

## 4. Sections in format version 1

Two: `languages` and `relations`.

### Languages

The eight fields above, and no others. The field names are the ones
`mclogiora_get_languages()` and `GET /mclogiora/v1/languages` already publish, so
an operator comparing an export against the REST surface finds one vocabulary
rather than two. The internal row id and the raw `LanguageStatus` constant are
both absent: the first means nothing off its own site, and `is_active` already
answers what the second is for.

Ordered by `code`, not by `order`. `order` is itself an exported field, so
sorting by it would make the file's shape depend on a value the file carries,
and reordering languages would rewrite the whole section for no semantic change.

### Relations

One entry per active translation group, ordered by `group_key`; items within a
group ordered by `language`.

Each item carries `object_type`, `language`, `status`, `is_source` and
`locator`. Absent by design: the relation row id, the group's numeric id, both
source hashes and both modified timestamps. The hashes and timestamps are the
change detector's private working state, whose meaning has already changed once
between phases and which the public API has twice declined to publish.

### Deliberately out of scope in slice 1

String translations, media metadata translations, menu and widget translation
records, routing and switcher settings, and feature flags are **not** exported.

None of them is excluded on principle; each is excluded because it is a separate
portability problem that this slice does not solve. Strings have their own
identity model and their own admin-screen import/export requirement in the
planning document. Media metadata is meaningless without addressing the
attachment, which is a file-path problem, and physical media never belongs in a
package. Settings need a per-setting audit of what is portable — `url_strategy`
reshapes every permalink on the destination — and dumping the option row because
it exists is how a package acquires scope nobody reviewed.

## 5. Identity

### Translation groups

The portable group identity is the **group UUID mcLogiora already assigns**.
That is what the UUID column is for; the auto-increment integer beside it is
where the row happens to live.

Carrying the UUID across sites makes an import idempotent: a second import of
the same package finds the group the first one created instead of building a
duplicate. Inventing a second group identity for packages would throw that away
and give the domain two answers to "which group is this".

### WordPress objects

**No package contains a post id or a term id, in either direction.**

Post 41 on the producing site and post 41 on the reading site have nothing to do
with each other. A package carrying ids would import into the wrong content or
refuse to import at all, and carrying them "for reference" only invites a later
apply to trust them.

Objects are named by an `ObjectLocator`:

| Kind | Fields |
| --- | --- |
| `post` | `post_type`, `slug`, and `ancestors` (root-first slug path) for hierarchical post types only |
| `term` | `taxonomy`, `slug` |

`ancestors` exists because WordPress keeps a slug unique *per parent* inside a
hierarchical type, not per type: `team` may exist once under `about` and again
under `company`. The ancestor path is compared exactly. A page moved to a
different parent is a different address, and adopting it because the last
segment still matches would silently relink content.

A term slug is unique within its taxonomy, so a term locator resolves to at most
one term on a healthy site. The resolver still counts matches rather than
assuming it.

`locator` is `null` when the exporting site could not build one — the object had
been deleted underneath the relation, or its content type has no locator in
format version 1 (`media`, `string`, `menu`, `widget`, `future`). Writing `null`
is deliberate: dropping the item would produce a package that looks complete and
is not.

### Statuses

The canonical `TranslationStatus` vocabulary, as strings: `original`, `missing`,
`draft`, `translated`, `needs_review`, `needs_update`, `machine_suggested`,
`disabled`. No PHP enum or class representation crosses the wire. A status
outside the list makes the whole package inapplicable — see §8.

## 6. Export

`McLogiora\ImportExport\PackageExporter` is an application service and belongs to
no transport. Package construction lives there and nowhere else; a REST route, a
WP-CLI command and an admin screen would each be a caller, and three callers
assembling a package is three subtly different formats.

**Export is read-only.** It does not repair a relation whose object was deleted,
does not normalise a status, does not touch a timestamp, does not save a setting
and does not refresh a cache. An export that changed the site it described would
make the description wrong the moment it was taken. This is asserted against
real WordPress by snapshotting every mcLogiora table plus posts, terms and
`mclogiora%` options before and after.

**Export makes no outbound request.** Neither does parsing, validation or
planning. Nothing in this layer contacts a translation provider, at any point,
for any reason.

Nothing is serialized by reflection or by handing a domain object to the
encoder. Every field is projected by name, which is what keeps repositories,
value objects and the schema free to change without changing the wire format.

Groups are read one page at a time through
`TranslationRelationRepositoryInterface::active_group_keys()`, which was added
for this purpose. The pre-existing `all()` is capped at 100 rows and ordered by
recent activity — right for a dashboard, wrong for anything that must see every
group and see them in the same order twice.

## 7. Parsing

`PackageParser` treats its input as hostile and answers one question: is this
structurally a package?

`unserialize()` appears nowhere in this layer and must not. PHP object
deserialization on untrusted input is remote code execution with extra steps.

Refusals, all `WP_Error` with a `mclogiora_package_` prefix:

| Code | Cause |
| --- | --- |
| `empty` | Nothing, or whitespace |
| `too_large` | Larger than `PackageFormat::MAX_BYTES` (64 MB) |
| `invalid_json` | Not JSON, truncated, or nested deeper than 32 levels |
| `not_an_object` | Valid JSON, but a scalar or a list |
| `unknown_member` | A top-level member other than `manifest` or `payload` |
| `missing_manifest`, `missing_payload` | Envelope member absent or not an object |
| `unknown_format` | `format` missing or not `mclogiora/translation-package` |
| `missing_version`, `unsupported_version` | Format version absent, non-integer, or not 1 |
| `invalid_manifest_field` | A manifest field of the wrong type |
| `unknown_section`, `undeclared_section` | A section this version cannot read, or one the manifest does not declare |
| `invalid_section` | A declared section that is not a list |
| `invalid_language`, `duplicate_language` | A malformed language entry, a repeated code, or two defaults |
| `invalid_relation_group`, `duplicate_relation_group` | A malformed group, a repeated key, an empty item list, or a group without exactly one source |
| `invalid_relation_item`, `duplicate_relation_item` | A malformed item, two items in one language, or an unreadable locator |
| `count_mismatch` | The manifest's counts disagree with the payload |

### Unknown fields

**Unknown keys inside a manifest, a language or a relation item are ignored.** A
package written by a later 1.x producer that added an optional field still reads
here.

**Unknown top-level members and unknown section names are refused.** An extra
top-level key means the envelope is not the envelope. An unknown section means
the file carries a whole domain of data this build would silently drop while
reporting a complete plan, and a plan that omits data without saying so is worse
than a refusal.

### Resource bounds

64 MB and 32 levels of nesting. Both are resource guards rather than product
limits: the relation section costs on the order of a hundred bytes per item, so
64 MB is past half a million translated objects, and the format's own deepest
path is six levels. Nothing here recurses without a bound.

## 8. Validation

`PackageValidator` answers the different question of whether a parsed package
means anything **on this site**. Keeping it separate from parsing is what lets a
package be checked for shape before a destination is chosen, and lets the
destination be examined without re-reading a byte of JSON.

| Issue | Level | Meaning |
| --- | --- | --- |
| `schema_not_installed` | error | mcLogiora's tables are absent here. Nothing is planned: with no schema every lookup reports "absent", and a plan built on that would propose creating the entire package on a site that cannot store any of it. |
| `unknown_status` | error | An item status outside the canonical vocabulary. The vocabulary is part of format version 1, so a package using a word mcLogiora does not have was not produced by mcLogiora or was edited afterwards. |
| `foreign_generator` | warning | Written by something other than mcLogiora. Read normally. |
| `plugin_version_differs` | warning | Produced by a different plugin version. Read normally. |

Per-item resolution belongs to the planner, so that a validation and a plan
cannot reach two different conclusions about the same item.

## 9. The import plan

`ImportPlanner::plan()` returns an `ImportPlan`: what an import would do,
computed without doing any of it.

**The dry run is the plan a later apply executes.** It is not a rehearsal that
apply repeats from scratch. Two code paths each working out what to do would
eventually work out different things, and the operator would have approved the
older one. Slice 2 consumes this operation list; it does not re-read the
package.

An `ImportPlan` is immutable and inert. Every accessor returns state the planner
already computed, so reading a plan twice cannot produce two answers and cannot
touch the site.

### Zero writes

Building a plan performs **0 inserts, 0 updates and 0 deletes**, across both
mcLogiora's tables and WordPress content. Every call the planner makes is a
read: repository finders, and a locator gateway that only queries. Proven by
snapshotting every mcLogiora table plus posts, postmeta, terms and `mclogiora%`
options around repeated planning runs, including against a destination full of
conflicts.

### The policy the plan encodes

**Import is additive.** It creates languages the destination does not have,
creates translation groups it does not have, and links objects that are not yet
linked. It never overwrites: not a language's metadata, not a translation's
status, not an occupied language slot, not the site's default language.

Wherever the package and the destination disagree about something that already
exists, the plan reports a conflict and plans nothing for it. That is a decision
rather than a gap. Overwriting needs a policy — which side wins, per field, per
domain — and no authoritative source has one. Inventing one inside a planner
would mean the first site to import a package discovers the policy by losing
work to it.

This is why there is no `update_language` and no `update_status` operation.

### Operations

| Type | Subject | Detail |
| --- | --- | --- |
| `create_language` | language code | The eight portable language fields |
| `create_group` | group key | `group_key`, `object_type`, resolved `object_id`, `language`, `status` (`original`), `locator` |
| `link_item` | `<group key>:<language>` | `group_key`, `object_type`, resolved `object_id`, `language`, `status`, `locator` |
| `skip` | language code or `<group key>:<language>` | `kind`, `reason` (`identical`, `source_present`, `item_present`) |

Operations are emitted in apply order: languages first, then each group's
`create_group` before its `link_item`s. Each carries the **resolved destination
identifier**, so applying the plan is a matter of executing it rather than
working out again what it meant.

### Issues

Four levels, which are four different things to do rather than a severity
ladder.

**`error`** — the package cannot be applied here at all, and the plan carries no
operations: `schema_not_installed`, `unknown_status`.

**`conflict`** — the destination contradicts the package; the item is left out
of the plan.

| Code | Meaning |
| --- | --- |
| `language_differs` | The language exists here with different metadata. Context lists each differing field with both values. |
| `default_language_differs` | Both sites declare a default and they disagree. |
| `language_slot_occupied` | That language slot of that group is filled here by a different object. |
| `item_status_differs` | Same object, same slot, different status. Context carries both statuses, whether `TranslationStatusTransitions` would permit the move, and the domain error code if not. |
| `object_already_grouped` | The resolved object already belongs to another group here. |
| `group_source_differs` | The group exists here around a different source object. |
| `group_without_source` | The group exists here with no source item. |
| `group_object_type_mismatch` | The package relates a post to a term. |
| `group_taxonomy_mismatch` | The package relates a term to a term in another taxonomy. |
| `duplicate_object_in_group` | Two languages of one group resolve to the same object. |

**`unresolved`** — a locator could not be followed. Context carries the locator
and every matching id.

| Code | Meaning |
| --- | --- |
| `locator_not_found` | Nothing here matches. |
| `locator_ambiguous` | More than one object matches. |
| `locator_incomplete` | The locator has no slug. WordPress leaves a draft without one until it is published, and mcLogiora creates translations as drafts, so this is a real limit of the format rather than a defect. |
| `locator_absent` | The package carried no locator: the exporting site could not address the object. |
| `locator_type_unknown` | The post type or taxonomy is not registered here. |

**`warning`** — worth reading, changes nothing: `foreign_generator`,
`plugin_version_differs`.

### Ambiguity is never resolved

A locator matching several objects is reported with every match listed. Taking
the lowest id would be arbitrary, would look like it worked, and would attach a
translation to whichever page happened to be created first. Likewise a locator
matching nothing is reported rather than approximated.

Locator resolution suppresses query filters. That is unusual enough to state:
identity resolution has to describe the database, not one plugin's view of it,
and a plugin filtering rows out of a query it never knew about would make the
planner report a language slot as free while the site can plainly see the post
sitting in it. All post statuses are searched except `auto-draft`, including
drafts, private posts and the trash, because a relation can point at any of
them.

### Determinism

Planning the same package against an unchanged site twice produces an identical
plan — same operations in the same order, same issues, same counts. Locator
resolution is memoised for the lifetime of one plan, so one plan gets one view
of the destination.

## 10. What does not exist yet

- No apply. No commit mode. No `dry_run=false`. No rollback, because nothing writes.
- No REST route, no WP-CLI command, no admin screen. The package layer is
  transport-neutral on purpose; the services are registered in the container and
  hooked to nothing, so a site that never imports anything runs no extra code.
- No remote or scheduled imports, and no upload handling.

Slice 2 owns apply, atomicity and rollback, and consumes the operation list
above unchanged.

## 11. Classes

| Class | Role |
| --- | --- |
| `PackageFormat` | Format identifier, version, sections, bounds, encoding flags |
| `TranslationPackage`, `PackageManifest`, `PackageLanguage`, `PackageRelationGroup`, `PackageRelationItem`, `ObjectLocator` | Immutable package model, shared by both directions |
| `PackageExporter` | Builds a package from the domain |
| `PackageEncoder` | Package to bytes |
| `PackageParser` | Bytes to package, or `WP_Error` |
| `PackageValidator` | Package against this site |
| `ObjectLocatorGatewayInterface`, `WordPressObjectLocatorGateway` | The only WordPress content lookup in the layer; read-only |
| `LocatorResolver`, `LocatorResolution` | Locator to zero, one or several objects |
| `ImportPlanner`, `ImportPlan`, `PlannedOperation`, `PlanIssue` | The dry run, and the plan a later apply executes |
