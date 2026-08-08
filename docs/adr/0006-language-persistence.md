# ADR 0006: Language Persistence

## Status

Accepted

## Context

Phase 07 is the first phase allowed to perform controlled CRUD operations on mcLogiora plugin data. Translation features remain out of scope, but language records need real persistence so later workflows can depend on durable active/default language state.

## Decision

Use `wp_mclogiora_languages` as the only Phase 07 write target.

Preserve existing language read method names while expanding the language repository and service contracts with explicit CRUD, lookup, default, and reorder operations.

Treat `language_code` as the stable edit identifier. Allow edits to locale, names, direction, status, order, and default state through dedicated operations. Keep code changes out of Phase 07.

Use repository-level validation for duplicate code, duplicate locale, invalid structures, multiple default states, default deletion, and referenced-language deletion.

Use a small object-cache decorator that caches only the ordered language list and invalidates that key after successful writes.

Keep the admin delete control disabled even though safe delete exists in the repository, because the UI does not yet display relation integrity details.

## Consequences

- Later translation phases can rely on durable language state.
- The default-language setup step can be completed without storing setup options.
- Cache invalidation remains simple and predictable.
- Translation writes, URL behavior, SEO output, REST, AJAX, switchers, and integrations remain deliberate future work.
