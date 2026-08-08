# ADR 0007: Translation Relation Persistence

## Status

Accepted

## Context

Phase 08 needs to make translation groups and items database-backed while still avoiding translated content creation. The existing Phase 06 schema already stores group UUIDs and item mappings. Phase 07 language persistence provides the active language checks needed for relation integrity.

## Decision

Use the existing Phase 06 relation tables without destructive schema changes.

Preserve existing relation repository and service method names while expanding the contracts with explicit group, item, status, metadata, lookup, archive, detach, and count operations.

Use UUID as the durable public group identifier. Numeric IDs remain internal and are supported only for lookup.

Treat relation items as generic object records using `object_type` and `object_id` terminology in the domain layer. Continue mapping these to `content_type` and `content_id` columns until a future migration justifies renaming.

Enforce relation integrity in the repository:

- One original per group.
- One active item per group language.
- One active group assignment per object type and object ID.
- Language must exist.
- Disabled languages cannot receive new relation items.
- Detach disables target items instead of deleting rows.
- Archive updates group status instead of deleting groups.

Keep Translation Manager write actions postponed. The manager can read relation records and display placeholder controls, but write forms need clearer integrity previews before they are exposed.

## Consequences

- Later content workflows can depend on durable relation records.
- Relation persistence remains independent of WordPress post, term, media, string, URL, and SEO writes.
- Cache invalidation stays simple and limited to group reads.
- Future phases can add reviewed admin write forms without changing the lower-level persistence contract.
