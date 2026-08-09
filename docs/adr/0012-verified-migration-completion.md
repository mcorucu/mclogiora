# ADR 0012: Verified Migration Completion

## Status

Accepted

## Context

Phase 11 added a WordPress integration suite. On its first run every test failed, and the reported state was contradictory:

- the stored schema version reached `2`
- `$wpdb->last_error` was empty
- `SchemaBuilder::table_exists()` reported **every** plugin table missing, including the Phase 06 tables
- therefore the fault appeared to predate Phase 11

Two hypotheses were raised and both turned out to be wrong. They are recorded here because ruling them out is part of the evidence.

**Hypothesis 1: dbDelta was misparsing the SQL.** Disproven by running dbDelta's own classification regex, `|CREATE TABLE ([^ ]*)|`, against the exact runtime SQL of all seven statements. Every statement was classified as a `CREATE TABLE` with the correct table name.

**Hypothesis 2: MySQL 8 strict mode rejected the zero-date defaults.** The CI server reported `sql_mode = NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` — `NO_ZERO_DATE` is not set, so the zero-date columns were accepted. Replacing them with `DEFAULT NULL` was correct hardening for stricter configurations, but it was not the cause.

## Root cause

A targeted probe answered it directly:

| Probe against `wptests_mclogiora_languages` | Result |
| --- | --- |
| `DESCRIBE` | **13 columns** |
| `SELECT COUNT(*)` | **0** |
| `SHOW TABLES LIKE 'wptests_mclogiora_languages'` | **NULL** |
| `$wpdb->last_error` | none |
| Server | MySQL 8.0.46 |

**The tables existed and were queryable the whole time.** The WordPress test suite rewrites every `CREATE TABLE` into `CREATE TEMPORARY TABLE` so tests stay isolated, and **MySQL temporary tables never appear in `SHOW TABLES`**.

`SchemaBuilder::table_exists()` used `SHOW TABLES LIKE`, so it reported a working schema as entirely absent. Every repository gates its reads and writes on that check, so all of them silently behaved as though the plugin had never been installed. That is why language creation quietly did nothing and seven downstream tests failed with `mclogiora_unknown_target_language` — a misleading symptom several layers away from the cause.

The migration layer had been working correctly all along.

## Decision

### 1. Table existence is checked with `DESCRIBE`

`SchemaBuilder::table_exists()` now runs a suppressed `DESCRIBE`, which sees permanent and temporary tables alike. It answers the question actually being asked — "can I use this table?" — rather than "is this table listed?".

A `table_columns()` helper was added on the same mechanism so migrations can assert structure, not just presence.

### 2. Schema version only advances after verified postconditions

This is the more important change, and it is required regardless of the root cause.

The old contract was `MigrationInterface::up(): void`. The runner called `up()` and immediately advanced the stored version. A migration that did nothing at all was indistinguishable from one that succeeded.

That failure mode is worse than a crash: a plugin that believes it is already upgraded **never retries**. The site is left permanently broken with no error and no path to recovery.

The contract is now:

```php
public function version(): string;
public function expected_tables(): string[];
public function up( SchemaBuilder $schema ): true|WP_Error;
```

Each migration runs its statements and then verifies that every table it declared actually exists, via the shared `MigrationPostconditions` trait. `MigrationRunner::run()` advances the stored version only on `true`, stops at the first failure, and returns the `WP_Error` to its caller. A failed migration therefore leaves the version untouched and is retried on the next attempt.

`$wpdb->last_error` is deliberately **not** used as the success signal. This incident proved it insufficient: a statement that is never executed leaves no error behind, and dbDelta suppresses errors while inspecting tables. Only the resulting schema is trustworthy.

### 3. Migration001's SQL is unchanged

The investigation cleared it. No renumbering, no rewriting, no retroactive edit.

## Consequences

- A broken installation now reports a specific, actionable `WP_Error` naming the missing tables, instead of silently pretending to be upgraded.
- `Installer::install()` returns the same result, so activation can surface failures later without further changes.
- Existing installations are unaffected: `DESCRIBE` and `SHOW TABLES` agree for permanent tables, and the version numbers and SQL are unchanged.
- Integration tests can finally observe the schema they create, which is what made the whole class of bug visible.

## Testing

Unit tests fix the invariant permanently: a failing first migration leaves the version at `0`; a failing second leaves it at `1`; both succeeding reaches `2`; a failed migration is retried; an up-to-date install runs nothing; migrations run in version order regardless of registration order.

Integration tests prove the real behaviour against MySQL: fresh install reaches the full schema and leaves repositories usable; installing repeatedly is idempotent and preserves data; upgrading from version 1 adds only the Phase 11 tables and preserves rows created beforehand; a migration whose tables do not materialise returns `mclogiora_migration_incomplete`; every managed table is owned by a migration; and the created schema has the intended columns, primary key, and unique indexes rather than merely existing.

The SQL mode is asserted as the server reports it and is never weakened to make a test pass.
