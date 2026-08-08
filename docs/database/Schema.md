# Database Schema

Phase 06 introduces the initial mcLogiora database schema through migrations.

## Tables

### `wp_mclogiora_languages`

Stores configured language records.

Columns:

- `id`: internal numeric primary key.
- `language_code`: stable language code such as `en` or `tr`.
- `locale`: WordPress locale such as `en_US`.
- `slug`: URL-ready language slug for future URL handling.
- `native_name`: language name in its own language.
- `english_name`: English label.
- `text_direction`: `ltr` or `rtl`.
- `status`: language status.
- `fallback_language_code`: future fallback reference.
- `sort_order`: display ordering.
- `is_default`: default-language marker.
- `created_at`: UTC creation datetime.
- `updated_at`: UTC update datetime.

### `wp_mclogiora_translation_groups`

Stores one durable UUID for each translation group.

Columns:

- `id`: internal numeric primary key.
- `group_uuid`: permanent public translation group identifier.
- `source_content_type`: original content type.
- `source_content_id`: original content identifier.
- `source_language`: original language.
- `status`: group status.
- `created_at`: UTC creation datetime.
- `updated_at`: UTC update datetime.

### `wp_mclogiora_translation_items`

Stores language-specific items inside a translation group.

Columns:

- `id`: internal numeric primary key.
- `group_uuid`: relation to `translation_groups.group_uuid`.
- `content_type`: object type such as `post`, `term`, `string`, `media`, `menu`, or `widget`.
- `content_id`: object identifier stored as text for cross-type flexibility.
- `language_code`: item language.
- `status`: translation status.
- `is_original`: source/original marker.
- `source_hash`: future source content hash.
- `translated_source_hash`: source hash at translation time.
- `source_modified_at`: future source modified datetime.
- `translation_modified_at`: future translation modified datetime.
- `created_at`: UTC creation datetime.
- `updated_at`: UTC update datetime.

## Naming Note

`translation_items` is the Phase 06 table name for the object-level mapping described as `translations` in the planning document. The current domain model uses `TranslationItem`, so the table name follows the current code contract while preserving the same purpose.

Phase 08 uses generic `object_type` and `object_id` terminology in services and documentation. These map to the existing `content_type` and `content_id` database columns until a future non-destructive migration justifies column renaming.
