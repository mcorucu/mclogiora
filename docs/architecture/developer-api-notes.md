# Developer API Notes

Phase 05 keeps developer APIs internal.

## Internal Helpers

Content foundation:

- `ContentTranslationServiceInterface::is_content_type_translatable()`
- `ContentTranslationServiceInterface::get_translatable_content_types()`
- `ContentTranslationServiceInterface::get_excluded_content_types()`

Taxonomy foundation:

- `TaxonomyTranslationServiceInterface::is_taxonomy_translatable()`
- `TaxonomyTranslationServiceInterface::get_translatable_taxonomies()`
- `TaxonomyTranslationServiceInterface::get_excluded_taxonomies()`

## Public Functions

No public helper functions are added in Phase 05.

Future public functions must use the `mclogiora_` prefix and be documented before release.

## Extension Boundary

Registries can later gain filters for third-party inclusion or exclusion rules. Phase 05 avoids those filters to keep the foundation predictable and read-only.
