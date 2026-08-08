# ADR 0010: Content & Taxonomy Translation Workflows

## Status

Accepted

## Context

Phases 04 through 09 built the relation model, its persistence, and the editor and compatibility foundations, but every write path was a placeholder. mcLogiora could describe translations and store relation records, yet a user could not actually create one.

Phase 10 turns that foundation into real workflows for posts, pages, public custom post types, categories, tags, and public custom taxonomies. It is the first phase in which the plugin creates and modifies WordPress content, so the boundaries below matter more than the features.

## Decision

### Workflow layer

WordPress mutation logic lives in `src/Workflows/`, not in admin screens:

| Class | Responsibility |
| --- | --- |
| `TranslationWorkflowService` | Facade the admin talks to; owns status changes |
| `ContentTranslationWorkflow` | Create, link, and unlink post translations |
| `TaxonomyTranslationWorkflow` | Create, link, and unlink term translations |
| `TranslationWorkflowValidator` | Every precondition, in one place |
| `TranslationStatusTransitions` | The status state machine |
| `SourceChangeTracker` | Marks translations outdated when a source changes |

`Admin\TranslationActionController` coordinates requests only: it reads and sanitizes input, verifies the nonce, delegates, and redirects. It holds no domain rules.

A narrow `WordPress\ContentGatewayInterface` sits between the workflows and the WordPress post and term APIs. It was added deliberately so workflow branching can be tested without a database, and it contains no domain logic. This is the only architectural addition made for testability, and it is a seam rather than an abstraction layer.

### Source and target semantics

The source of a group is its `original` item. Its language is the language recorded on its relation item, or the site default when the object is not yet in a group, because untranslated content is by definition in the default language.

A group has at most one item per language and an object belongs to at most one group. Both rules were already enforced by the Phase 08 persistence layer; the workflows validate them before writing so users get a specific message instead of a database error.

### Post draft copy policy

A created translation is always `post_status = draft`. Only `post_title`, `post_content`, `post_excerpt`, `post_type`, `menu_order`, and the source author are copied.

**Arbitrary post meta is not copied, and this is deliberate.** Meta is an unbounded, plugin-owned namespace. Copying it would duplicate SEO fields, ACF values, builder payloads, and license or transient data belonging to plugins mcLogiora knows nothing about. Some of that data is language-specific, some contains IDs that must not be shared between posts, and some is actively harmful when duplicated. A translation that silently inherits another plugin's state is worse than one that starts clean, because the damage is invisible until that plugin misbehaves. Field-level copying belongs to the phases that own those fields: featured images in Phase 11, SEO metadata in Phase 13, ACF and builder payloads in Phases 14 and 15.

The slug is left to WordPress. Translated slugs are a Phase 12 concern, and inventing one now would create data the slug layer would have to unpick.

### Term translation policy

Term translations are **not** created by copying the source. A duplicated term name is not a translation, so the user must supply the translated name; the description is optional.

A provisional slug is generated as `sanitize_title( name-languagecode )`. It exists only to avoid a collision when a translated name transliterates to the source slug. It is deterministic and language-scoped so the Phase 12 slug manager can recognise and replace it, and it is never presented to the user as a translated slug.

A translated parent is used only when the source's parent already has a translation in the target language. Otherwise the term is created at the top level. Attaching a translated child to a parent in a different language would produce a mixed-language hierarchy that later URL and archive work could not resolve.

### Link and unlink semantics

**Link** records a relation and nothing else. No content is copied, no status is changed, no field is written on the target. Linked content enters as `needs_review`, because content translated outside mcLogiora has not been verified against the current source.

Translations stay within one post type, and terms within one taxonomy. A page is not a translation of a post: they differ in templates, capabilities, and archive behaviour, and mixing them would make later URL and SEO work ambiguous.

**Unlink** removes the relation record only. The WordPress post or term keeps its content, meta, status, revisions, and term assignments. Nothing is trashed and nothing is deleted. The source cannot be unlinked while translations remain, because that would orphan them; changing which item is the source is a separate reassignment workflow that does not exist yet.

### Status transition model

`TranslationStatusTransitions` is the only place a status change is decided. Supported transitions:

```
draft         → needs_review, translated
needs_review  → translated, draft
translated    → needs_update, needs_review
needs_update  → needs_review, translated
```

Every state may also move to `disabled`, and `disabled` may return to `needs_review`.

Four statuses are treated specially:

- `original` is a structural role, not a workflow status. It cannot be assigned and cannot be left.
- `missing` describes an absent translation. It is computed, never stored as a transition target.
- `machine_suggested` is reserved for Phase 16. It can be left so future suggestions can be reviewed, but Phase 10 offers no action that enters it.
- `disabled` is administrative.

Transitions are validated against the *stored* status, so a stale form cannot force an illegal change. Invalid transitions return `WP_Error` and write nothing.

### Source change tracking

`SourceChangeTracker` hashes the fields a translator acts on — title, content, excerpt — and marks translations `needs_update` when that hash changes. Post status, dates, author, and meta are excluded, so publishing or reassigning a post does not invalidate translations that are still accurate.

Autosaves, revisions, bulk edits, auto-drafts, trashed posts, and `inherit` statuses are ignored. Only the group's source can invalidate translations, which is also what prevents a loop when a translation is saved.

There is no semantic diffing and no attempt to judge how significant a change is. A conservative hash that occasionally over-reports is honest; a heuristic that under-reports would silently leave stale translations marked current.

### Failure compensation

Creating a translation is two operations: a WordPress object is created, then a relation is written. If the relation write fails, the object created **by that same call** is deleted and the error is returned.

The rollback is deliberately narrow:

- Only the object this operation just created is removed.
- Pre-existing content is never deleted. A failed **link** performs no rollback at all, because the target belonged to the user before the call.
- No database transaction wraps WordPress hooks. `wp_insert_post()` fires actions that other plugins use for indexing, caching, and external writes; holding a transaction across them risks lock contention and inconsistency with side effects that are not transactional anyway.

### Security model

Every mutation requires an explicit user action, a nonce, a capability check, sanitization, validation, escaping on output, and a safe redirect. Capability is checked inside each workflow method as well as at the request boundary, so authorisation does not depend on the controller. UI that hides an action is never treated as a control: the status policy is re-validated server side even though the screen only renders permitted transitions.

Admin flows are traditional secured POST to `admin-post.php`. No REST route and no AJAX handler is added in this phase.

## Consequences

- Users can create, link, unlink, and progress translations for the supported content types.
- WooCommerce and LMS content remains excluded through the existing registries, and remains planned as future free compatibility modules.
- No frontend behaviour changes: no routing, switcher, hreflang, or SEO output.
- The workflows are unit tested without a WordPress installation, which keeps the suite fast and makes failure paths such as rollback directly exercisable.

### Extension points for later phases

| Phase | Extends |
| --- | --- |
| 11 | Featured image and media handling in `build_translation_postarr()`; menu and widget relations reuse the group model |
| 12 | Replaces provisional term slugs; adds translated post slugs |
| 13 | Reads groups to emit hreflang and canonical output |
| 14 | Editor surfaces call the same workflow services rather than reimplementing them |
| 16 | Enters `machine_suggested`, which the transition table already supports leaving |
| 17 | REST and WP-CLI wrap `TranslationWorkflowService` without duplicating rules |
