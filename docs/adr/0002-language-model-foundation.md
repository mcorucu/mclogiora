# ADR 0002: Language Model Foundation

## Status

Accepted

## Context

Phase 03 needs to prepare language management architecture without implementing full multilingual functionality or persistence. The planning document proposes language storage later, but this phase explicitly prohibits database schema, options persistence, REST endpoints, AJAX handlers, and real settings writes.

## Decision

Introduce a language value object, status constants, repository and service contracts, locale validation, RTL detection, and an in-memory repository for mock admin data.

Add the `mcLogiora -> Languages` admin page as a placeholder backed by the in-memory repository. Add the setup wizard as a placeholder route with planned steps.

Define planned capabilities in `CapabilityRegistry`, but resolve them to `manage_options` for now so roles are not modified.

## Consequences

- Future persistence can replace `InMemoryLanguageRepository` behind `LanguageRepositoryInterface`.
- Admin UI can be reviewed before data writes exist.
- No activation role mutation or schema migration is needed in this phase.
- Later phases must deliberately add schema, options, migrations, REST, AJAX, and role mapping instead of inheriting hidden behavior from the foundation.
