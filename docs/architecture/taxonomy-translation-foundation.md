# Taxonomy Translation Foundation

Phase 05 prepares taxonomy awareness without creating or changing terms.

## Model

`TranslatableTaxonomy` describes a WordPress taxonomy:

- Name.
- Label.
- Public state.
- Built-in state.
- Translatable state.
- Exclusion reason.

## Registry

`TaxonomyRegistryInterface` defines read-only taxonomy discovery.

`TaxonomyRegistry` reads WordPress taxonomy registration metadata through `get_taxonomies()` when available. It does not query terms, create terms, copy term meta, write options, create tables, or schedule work.

## Support Detector

`TaxonomySupportDetector` supports:

- `category`
- `post_tag`
- Public custom taxonomies

## Placeholder Service

`TaxonomyTranslationServiceInterface` exposes internal helper methods:

- `is_taxonomy_translatable()`
- `get_translatable_taxonomies()`
- `get_excluded_taxonomies()`
- `get_support_overview()`

These are internal PHP services only. No REST endpoints or term actions are exposed in Phase 05.
