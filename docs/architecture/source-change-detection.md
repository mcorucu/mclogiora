# Source Change Detection Concept

Phase 04 prepares metadata for future outdated detection without hashing real content.

## Prepared Metadata

`TranslationItem` carries:

- `source_hash`
- `translated_source_hash`
- `source_modified`
- `translation_modified`

## Detector Contract

`NeedsUpdateDetectorInterface` defines the future detection boundary.

`MetadataNeedsUpdateDetector` only evaluates already-provided metadata. It does not inspect WordPress posts, terms, strings, media, builder content, blocks, files, or remote services.

## Future Work

Later phases can add source hash generation per content type after content modules and builder adapters exist. That work must be explicit, tested, and context-aware.
