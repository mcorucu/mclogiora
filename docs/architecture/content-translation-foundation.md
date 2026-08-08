# Content Translation Foundation

Phase 05 prepares content type awareness without creating or changing posts.

## Model

`TranslatableContentType` describes a WordPress post type:

- Name.
- Label.
- Public state.
- Built-in state.
- Translatable state.
- Exclusion reason.

## Registry

`ContentTypeRegistryInterface` defines read-only content type discovery.

`ContentTypeRegistry` reads WordPress post type registration metadata through `get_post_types()` when available. It does not query posts, create posts, copy post meta, write options, create tables, or schedule work.

## Support Detectors

- `PostSupportDetector` supports built-in `post` and `page`.
- `CustomPostTypeSupportDetector` supports public custom post types.

## Placeholder Service

`ContentTranslationServiceInterface` exposes internal helper methods:

- `is_content_type_translatable()`
- `get_translatable_content_types()`
- `get_excluded_content_types()`
- `get_support_overview()`

These are internal PHP services only. No REST endpoints or content actions are exposed in Phase 05.
