# Translation Relation Architecture

Phase 08 makes the conceptual relation model persistence-backed for plugin-owned relation records.

## Model

`TranslationGroup` groups one original item with zero or more target translation items.

`TranslationItem` describes one object-language pair:

- Content type.
- Object key.
- Language code.
- Status.
- Original/source flag.
- Future source hash metadata.
- Future translated-source hash metadata.
- Future source modified timestamp.
- Future translation modified timestamp.

## Object Model

`TranslationItem` keeps the original method names `content_type()` and `object_key()` for compatibility, and also exposes `object_type()` and `object_id()` aliases for the generic relation model.

The built-in object type constants are:

- `post`
- `term`
- `string`
- `media`
- `menu`
- `widget`
- `future`

Additional object types may use sanitized machine keys. Relation persistence does not assume WordPress posts.

## Repository

`TranslationRelationRepositoryInterface` defines the persistence boundary.

`DatabaseTranslationRelationRepository` is the production repository. It writes only to:

- `wp_mclogiora_translation_groups`
- `wp_mclogiora_translation_items`

`InMemoryTranslationRelationRepository` remains available for tests and fixtures.

## Service

`TranslationRelationServiceInterface` now supports:

- Create empty groups.
- Create groups from source objects.
- Attach existing objects as translations.
- Safely detach target items by disabling them.
- Get translation sets for objects.
- Determine missing languages.
- Mark relation item statuses.
- List relation groups.

The service delegates to the repository and metadata-only needs-update detector. It does not create or mutate WordPress content.

## Integrity Rules

- UUID is the durable public group identifier.
- A group may have only one original/source item.
- A group may have only one active item per language.
- An object may belong to only one active group for the same object type.
- A language must exist before a relation item is created.
- Disabled languages cannot receive new relation items.
- Detach is a non-destructive status change to `disabled`.
- Groups are archived by status, not deleted.

## Admin

`mcLogiora -> Translation Manager` reads relation records and shows:

- Content type filter.
- Source language filter.
- Target language filter.
- Status filter.
- Search.
- Translation status table.
- Disabled future action buttons for attach, status, review, and archive workflows.

No admin action changes relation data in Phase 08. Admin write forms are postponed because relation writes need clearer integrity previews before they are exposed to site administrators.
