# Language Persistence

Phase 07 introduces the first controlled CRUD operations for mcLogiora-owned data.

## Storage

Languages are stored in `wp_mclogiora_languages`.

Stable external identifiers:

- `language_code`
- `locale`

Internal implementation fields:

- Numeric `id`
- `slug`
- `fallback_language_code`
- Timestamps

The language code is treated as the stable edit identifier. Phase 07 does not expose code changes after creation.

## CRUD Lifecycle

Create:

- Admin submits language fields through `LanguageManager` or the setup default-language step.
- The module verifies capability and nonce.
- `LanguageService` sanitizes input and creates a `Language` value object.
- `DatabaseLanguageRepository` validates uniqueness and inserts the row.
- If the new language is default, existing default markers are cleared first.

Update:

- Admin edits locale, names, direction, status, and order.
- Language code remains read-only.
- Default state is preserved during edit. Moving the default marker uses `set_default()`.

Enable and disable:

- Enable sets status to `active`.
- Disable sets status to `inactive`.
- Default language cannot be disabled.

Set default:

- Repository clears all existing default markers.
- Target language is marked default and active.
- This operation can also repair accidental multiple-default states.

Reorder:

- Admin submits numeric ordering values.
- Service sorts submitted language codes by number.
- Repository writes sequential `sort_order` values.

Delete:

- Repository supports safe delete.
- Default language cannot be deleted.
- Languages referenced by translation groups or translation items cannot be deleted.
- The Phase 07 admin UI keeps delete disabled until integrity information can be displayed.

## Validation Rules

The repository rejects:

- Duplicate locale.
- Duplicate language code.
- Invalid locale structure.
- Invalid language code structure.
- Existing multiple-default data during normal create/update.
- Deleting the default language.
- Deleting a referenced language.

The service also rejects missing language code and invalid locale before building the domain object.

## Cache Invalidation

Only the ordered language list is cached under the mcLogiora object-cache group.

Derived reads:

- Active languages.
- Default language.
- Lookup by code.
- Lookup by locale.

Successful writes delete the single list cache key. Failed writes do not invalidate cache.

## Boundary

Language persistence does not create translations, relation rows, content, terms, metadata, URLs, SEO tags, switchers, REST routes, AJAX actions, scheduled events, role mutations, or remote requests.
