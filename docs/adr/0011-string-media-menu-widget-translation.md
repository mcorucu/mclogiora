# ADR 0011: String, Media, Menu & Widget Translation

## Status

Accepted

## Context

Phase 10 gave mcLogiora real translation workflows for posts and terms. Everything else a multilingual site needs — interface strings, media metadata, navigation menus, and widget text — still had no home.

These four domains look similar from a distance and are structurally very different up close. Phase 11 implements each on its own terms rather than forcing one model onto all four.

## Decision

### Where each domain stores its data

| Domain | Storage | Why |
| --- | --- | --- |
| Strings | New `mclogiora_strings` + `mclogiora_string_translations` tables | A string has no WordPress object to relate to |
| Media | New `mclogiora_media_translations` table | Language-specific text attached to one shared attachment |
| Widgets | New `mclogiora_widget_translations` table | Named fields, not an object relation |
| Menus | **Existing relation model** | A menu is a term and its items are posts, both already representable |

The generic translation group relates WordPress objects to each other. Strings, media metadata, and widget fields have no second object to relate to, so using groups would mean inventing synthetic object IDs whose only purpose is to satisfy the schema. Menus genuinely fit and therefore reuse it.

All new tables arrive through `Migration002TranslationDomains` at database version 2. `Migration001InitialSchema` is untouched. The migration runs through `dbDelta` via `SchemaBuilder`, so it is idempotent, non-destructive, and safe on both fresh installs and upgrades.

### String registry identity

A string's identity is `sha1( text + text_domain + context )`, stored as a unique `string_hash`.

Context is part of identity because that is the entire point of gettext contexts: "Order" as a noun and "Order" as a verb need different translations, and collapsing them guarantees one of the two is wrong. Text domain is included because the same English word in two plugins is two independent translation decisions.

The hash rather than the text carries the unique index, since `source_text` is `longtext` and cannot be indexed usefully.

### Why scans are explicit, and never automatic

Scanning walks a plugin or theme tree, opens every PHP file, and tokenises it. That is far too expensive to do per request. More importantly, an implicit scanner would mean ordinary visitor traffic could write rows to the database, so a busy site would grow its string table as a side effect of being visited.

Scanning therefore happens only when an authorised administrator submits the scan form. There is no cron job, no lazy trigger on a cache miss, and no hook that scans "just once" on activation.

### Scanner behaviour and honesty

Source is parsed with PHP's own tokeniser, not regular expressions, and is never executed or included. Tokenising means a call inside a comment or a quoted string is correctly ignored, and PHP quoting rules are respected.

Only statically resolvable literal arguments are recorded. `__( $label, 'domain' )` cannot be known without running the code, so it is counted as unresolvable and reported, not guessed. Recording `$label` as a source string would be worse than recording nothing: it would put a variable name in front of a translator.

Recognised: `__`, `_e`, `_x`, `_ex`, `_n`, `_nx`, `_n_noop`, `_nx_noop`, and the `esc_html_*` / `esc_attr_*` variants.

### Scanner security

Request data never becomes a filesystem path. The form submits a scope *kind* (theme or plugin) and a directory *name*. `ScanScope` maps the kind to a fixed root, rejects any name containing a separator, `..`, or a character outside `[A-Za-z0-9_.-]`, resolves the result with `realpath()`, and then verifies the resolved path is still inside the root. A traversal attempt fails at least twice over.

Scanning is read-only. No third-party file is ever written. `vendor`, `node_modules`, `.git`, caches, and build directories are skipped, only `.php` files are read, and per-file and per-scan limits bound the work. Unreadable files are skipped rather than aborting the scan.

Scanning requires `install_plugins` in addition to the mcLogiora translation capability. A user who can already upload arbitrary code to the site is not escalated by being allowed to read it, but a mere translator should not be able to read source.

### Strings go stale, they are never deleted

A rescan marks the strings in its scope stale, then registers what it finds. Anything not found stays stored and flagged.

Deleting missing strings would delete their translations. Deactivating a plugin for an afternoon would then silently destroy translation work that took weeks. Stale is recoverable; deleted is not.

### Media: one file, many languages

A translated alt text is a text change, not a new image. Duplicating the binary would multiply storage, break deduplication, orphan generated image sizes, and force every copy to be re-optimised — all to change a caption.

So there is exactly one WordPress attachment. Title, alternative text, caption, and description are stored per language; the file, mime type, dimensions, generated sizes, and ownership are untouched. Fields with no translation fall back to the attachment's own values, so a partially translated attachment still renders completely.

A plugin-owned table is used rather than postmeta. Four fields per language per attachment would otherwise be four unindexed postmeta rows each, and "the alt text for this attachment in this language" would become a `meta_key LIKE` scan. One indexed row answers it in a single lookup, and uninstall becomes a table drop instead of a meta sweep.

### Featured image policy

Phase 10 deliberately deferred this. The policy: **a translated post references the same attachment as its source.** mcLogiora never duplicates the file and never creates a media clone.

An editor who genuinely needs a different image for one language sets it through the normal WordPress featured image control, and that choice wins — `resolve_featured_attachment()` prefers the translation's own thumbnail and only falls back to the source's.

### Menu duplication whitelist

A translated menu is a real, separate WordPress menu, so it stays editable in Appearance where people expect it.

Menu items are posts, which means they can carry meta from any plugin that decided to attach some. Only these core fields are copied:

`menu-item-title`, `-url`, `-type`, `-object`, `-object-id`, `-target`, `-attr-title`, `-description`, `-classes`, `-xfn`, `-status`, `-position`

Everything else is left behind deliberately. Theme location assignments are never written: which menu appears in which location per language is rendering, and rendering is Phase 12.

### Menu hierarchy

Items are created first with no parent, recording a map from source item ID to new item ID. Parents are rebuilt from that map in a second pass.

A single pass cannot work, because a child can appear before its parent in menu order and the new parent ID does not exist until the parent has been created. Skipping the second pass would leave translated items pointing at *source* menu-item IDs, silently entangling the two menus.

### Internal-link fallback

When a menu item links to a post or term that already has a translation in the target language, the copied item points at that translation.

When it does not, the item keeps pointing at the source object. The alternatives are worse: inventing a translation that does not exist, or leaving a dead link. Pointing at the source means the menu works today, and re-running the workflow after translating the target picks up the better link. Custom URLs are copied as-is; translated URLs are Phase 12.

### Widget adapter model

Widget instances are opaque option arrays whose shape is decided entirely by whichever plugin registered the widget. An adapter is how mcLogiora learns that a given key holds human-readable text rather than a colour, an ID, or a serialized blob.

Core adapters ship for the Text, Custom HTML, and Block widgets. Third-party widgets register adapters through the `mclogiora_widget_adapters` filter.

**A widget with no adapter is reported as unsupported and left completely untouched.** Guessing which keys of an unknown array hold translatable text would eventually rewrite a setting that merely looked like a sentence — a CSS class, an API key, a template name.

Translations are stored beside the widget instance, never inside it. The source `widget_*` option is never rewritten, so the original text always survives, uninstalling is a clean table drop, and one language can never overwrite another's copy. `sidebars_widgets` is not duplicated per language.

### Frontend rendering is deliberately deferred

Nothing in Phase 11 hooks `gettext`, filters widget output, or swaps menu locations. Every lookup API takes an **explicitly named language**:

```php
$service->translate( $text, 'tr', 'mclogiora', $context );
$media->metadata_for_language( $attachment_id, 'tr' );
$widgets->apply_for_language( $type, $id, 'tr', $instance );
```

None of them ask "what language is the current request?". Answering that is Phase 12's job, and building a second answer here would create two competing sources of truth that would immediately disagree.

### Integration test architecture

Phase 10 had strong unit tests but nothing proving the workflows actually satisfy WordPress. Phase 11 adds a minimal harness using the official WordPress PHPUnit suite: `bin/install-wp-tests.sh` provisions core and the test library, `tests/bootstrap-integration.php` loads the plugin inside it, and `phpunit-integration.xml.dist` runs `tests/Integration`.

Integration tests cover only what doubles cannot prove: that `wp_insert_post` and `wp_insert_term` accept what the workflows send, that provisional term slugs really do avoid collision, that menu hierarchy survives a round trip through the menu API, that attachment metadata persists, that featured images are referenced rather than duplicated, that widget options are not rewritten, and that the migration produces usable tables and is idempotent.

Unit tests remain the primary suite. One WordPress runtime and one database are enough; broadening the matrix belongs to Phase 18. No integration test contacts an external service.

## Consequences

- Four new admin capabilities' worth of surface area, all behind explicit actions, nonces, capability checks, sanitization, validation, and safe redirects.
- Database version moves to 2. Uninstall remains a clean drop of plugin-owned tables.
- The plugin now ships a real translation catalogue: `composer pot` regenerates `languages/mclogiora.pot` from source using the official WP-CLI i18n tooling.
- Caches are invalidated per affected key only, on string writes, media writes, and widget writes.

### Extension points for Phase 12

| Need | Existing seam |
| --- | --- |
| Apply string translations at runtime | `StringTranslationService::translate()` — pass the resolved request language |
| Apply media metadata at runtime | `MediaTranslationService::metadata_for_language()` |
| Apply widget text at runtime | `WidgetTranslationService::apply_for_language()` |
| Choose a menu per language | Menu relations are already stored as translation groups |
| Replace provisional term slugs | Slugs are deterministic and language-suffixed, so they are recognisable |
