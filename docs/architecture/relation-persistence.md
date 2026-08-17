# Relation Persistence

Phase 08 persists plugin-owned translation relation records.

## Tables

Relation persistence uses the Phase 06 tables:

- `wp_mclogiora_translation_groups`
- `wp_mclogiora_translation_items`

No translated WordPress posts, terms, media, strings, menus, widgets, URLs, or SEO records are created.

## Group Lifecycle

Groups are identified publicly by `group_uuid`.

Supported repository operations:

- Create an empty group.
- Create a group from a source object.
- Fetch a group by UUID.
- Fetch a group by internal ID.
- Fetch a group by source item.
- Update group metadata.
- Archive a group by status.

Archive is non-destructive. Phase 08 does not expose destructive delete flows.

## Item Lifecycle

Items use the generic object model:

- `object_type`
- `object_id`
- `language_code`

The current database columns remain `content_type` and `content_id` for compatibility with the Phase 06 schema. Domain objects expose both the old method names and generic aliases.

Supported repository operations:

- Add an item to a group.
- Update item status.
- Update item language.
- Update source metadata placeholder fields.
- Fetch item by object type, object ID, and language.
- Fetch items by group.
- Fetch items by status.
- Fetch source/original item for group.
- Check whether an object is already assigned to an active group.
- Safely detach a target item by setting status to `disabled`.

## Integrity Rules

- One original/source item per group.
- One active item per group per language.
- One object can belong to only one active group for the same object type.
- A language must exist before a relation item is created.
- Disabled languages cannot receive new relation items.
- Original/source items cannot be detached in Phase 08.
- Groups are archived, not deleted.

The source language is not forced to be the site default language. This keeps the model safe for future migrations and source reassignment workflows.

## Cache Strategy

The relation cache is intentionally small:

- Active group list.
- Individual group lookup by UUID.

Successful group and item writes invalidate the active group list. Writes that know the group UUID also invalidate that group cache key. Failed writes do not invalidate cache.

Item writes that receive only an object identity resolve the affected group key
before mutation, so targeted invalidation remains correct with a persistent
WordPress object cache. The decorator owns this lookup; workflows do not know
cache keys.

## Admin Limitations

The Translation Manager reads and displays relation records, missing languages, statuses, and placeholder action buttons.

Admin write forms are postponed because relation writes need integrity previews before site administrators can safely attach, detach, or archive records. No AJAX or REST handlers are introduced.

## Boundary

Relation persistence writes only plugin relation tables. It does not create WordPress content, terms, media, strings, menus, widgets, URLs, SEO tags, switchers, external calls, scheduled events, role changes, REST endpoints, or AJAX handlers.
