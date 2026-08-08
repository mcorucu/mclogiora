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

## Phase 11 tables (database version 2)

Added by `Migration002TranslationDomains`. Migration001 is unchanged.

### `{prefix}mclogiora_strings`

Registered source strings, independent of any language.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned | Primary key |
| `string_hash` | char(40) | **Unique.** `sha1( text + domain + context )` |
| `source_text` | longtext | Not indexed; the hash carries identity |
| `text_domain` | varchar(191) | Indexed |
| `context` | varchar(191) | Part of identity |
| `source_type` | varchar(20) | Indexed. theme, plugin, core, manual |
| `source_reference` | varchar(191) | Relative file path where known |
| `source_line` | int unsigned | Line number where known |
| `is_stale` | tinyint(1) | Indexed. Not seen in the last scan |
| `first_seen_at` / `last_seen_at` | datetime | |

The unique hash makes rescanning idempotent. Context is part of identity because the same word can require different translations in different contexts.

### `{prefix}mclogiora_string_translations`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned | Primary key |
| `string_id` | bigint unsigned | **Unique with `language_code`** |
| `language_code` | varchar(20) | Indexed |
| `translated_text` | longtext | |
| `status` | varchar(20) | Indexed |
| `updated_at` | datetime | |

### `{prefix}mclogiora_media_translations`

Language-specific text for one shared attachment. The file is never duplicated.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned | Primary key |
| `attachment_id` | bigint unsigned | **Unique with `language_code`** |
| `language_code` | varchar(20) | Indexed |
| `translated_title` | text | |
| `translated_alt_text` | text | |
| `translated_caption` | text | |
| `translated_description` | longtext | |
| `status` | varchar(20) | |
| `updated_at` | datetime | |

A plugin-owned table is used rather than postmeta so that "alt text for this attachment in this language" is a single indexed lookup rather than a `meta_key LIKE` scan, and so uninstall is a table drop rather than a meta sweep.

### `{prefix}mclogiora_widget_translations`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned | Primary key |
| `widget_key` | varchar(191) | **Unique with `language_code`.** `type:instance` |
| `adapter_id` | varchar(64) | Indexed |
| `language_code` | varchar(20) | Indexed |
| `translated_fields` | longtext | JSON object of adapter-declared fields |
| `status` | varchar(20) | |
| `updated_at` | datetime | |

Field sets differ per widget type, so a column per field is impossible; the adapter owns the shape. The source `widget_*` option is never modified, so this table is purely additive.

### Menus

Menus add no table. A WordPress menu is a term and its items are posts, so translated menus are recorded in the existing translation group and item tables.
