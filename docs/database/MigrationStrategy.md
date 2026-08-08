# Migration Strategy

Phase 06 introduces a migration architecture:

```text
Activation
  -> Installer
  -> MigrationRunner
  -> Migration classes
  -> SchemaBuilder
  -> dbDelta()
```

## Rules

- Activation must not call `dbDelta()` directly.
- Each migration has a stable target database version.
- Database version is stored separately from plugin version in `mclogiora_db_version`.
- Migrations run in version order.
- A migration updates the stored database version only after it completes.
- Future migrations should be additive whenever possible.
- Destructive migrations require a dedicated data-retention and rollback plan.

## Current Migration

- `Migration001InitialSchema`: creates languages, translation groups, and translation items.

## Repository Replacement

Phase 06 switches service bindings from in-memory repositories to cached database repositories. In-memory repositories remain available for tests and fixtures.
