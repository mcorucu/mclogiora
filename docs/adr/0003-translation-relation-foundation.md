# ADR 0003: Translation Relation Foundation

## Status

Accepted

## Context

mcLogiora needs a stable way to conceptualize originals and translations before implementing content workflows. Phase 04 must not create tables, persist options, create posts, register REST or AJAX handlers, call external services, or mutate content.

## Decision

Use a group-based relation model:

- `TranslationGroup` represents a set of related language alternatives.
- `TranslationItem` represents one language-specific item inside a group.
- `TranslationStatus` defines relation status values.
- `ContentType` defines foundation object types.
- `TranslationRelationRepositoryInterface` defines the future persistence boundary.
- `TranslationRelationServiceInterface` defines read and placeholder operations for relation lookup, missing languages, and outdated translations.

Use an in-memory repository for mock data only. Add source-change metadata fields and a detector interface, but do not hash real content.

## Consequences

- The admin placeholder can show how relation management will feel without writing data.
- A later database repository can replace the in-memory repository behind the same interface.
- Content modules can build on relation contracts without coupling to storage.
- Schema, migrations, real workflows, and source hashing remain explicit future work.
