# ADR 0005: Database Persistence Layer

## Status

Accepted

## Context

mcLogiora needs durable language and translation relation storage before real multilingual workflows can be implemented. The persistence layer must support future upgrades, stable public identifiers, cacheable reads, and WordPress.org-compatible installation behavior.

## Decision

Introduce an installer and migration architecture:

- `Activation` calls `Installer`.
- `Installer` calls `MigrationRunner`.
- `MigrationRunner` executes migration classes.
- Migration classes use `SchemaBuilder`.
- `SchemaBuilder` is the only layer that calls `dbDelta()`.

Track database schema version independently from plugin version using `mclogiora_db_version`.

Use UUIDs as permanent translation group identifiers. Numeric auto-increment IDs remain internal implementation details only.

Preserve repository interfaces and replace in-memory bindings with cached database-backed repositories. Keep in-memory repositories available for tests and fixtures.

## Consequences

- Future migrations can be added without expanding activation logic.
- Translation group identifiers can survive table reshaping and import/export work.
- Services and admin screens remain decoupled from persistence details.
- Activation now creates schema through the migration runner, but it still avoids role mutation, scheduled events, remote calls, and content mutation.
