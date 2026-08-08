# Relation Repository Replacement Plan

Phase 08 completed the repository replacement. `DatabaseTranslationRelationRepository` is now the production binding, wrapped by `CachedTranslationRelationRepository`.

## Replacement Target

The database-backed implementation preserves the original read methods and adds controlled plugin-data writes for groups and items.

## Planned Tables

The implemented tables are:

- `wp_mclogiora_translation_groups`
- `wp_mclogiora_translation_items`

Schema creation was introduced in Phase 06. Phase 08 uses those tables without destructive migrations.

## Replacement Rules

- Keep legacy service method names available.
- Use UUID as the durable public group identifier.
- Avoid direct SQL outside repository classes.
- Keep admin writes postponed until non-AJAX forms can show integrity risks clearly.
- Keep all future admin writes behind capability and nonce checks.
