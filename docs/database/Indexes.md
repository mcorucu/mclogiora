# Indexes

Phase 06 creates only indexes expected by the current architecture.

## Languages

- `UNIQUE language_code`: direct lookup by language code.
- `UNIQUE locale`: direct lookup by WordPress locale.
- `KEY status`: filtering active/inactive languages.
- `KEY sort_order`: language manager display order.
- `KEY updated_at`: future maintenance and sync checks.

## Translation Groups

- `UNIQUE group_uuid`: permanent public identifier lookup.
- `KEY source_lookup (source_content_type, source_content_id, source_language)`: find a group from an original item.
- `KEY source_language`: filter groups by source language.
- `KEY status`: filter groups by lifecycle state.
- `KEY updated_at`: manager screens and future maintenance.

## Translation Items

- `KEY group_uuid`: load all items in a group.
- `KEY language_code`: filter by target/source language.
- `KEY content_lookup (content_type, content_id)`: find relation data for a content object.
- `KEY status`: translation manager status filters.
- `KEY updated_at`: manager screens and future maintenance.
- `UNIQUE content_language (content_type, content_id, language_code)`: prevent duplicate language records for the same object.

## Index Discipline

No speculative indexes are added for future features such as URLs, slugs, strings, media files, jobs, import/export, or external providers.
