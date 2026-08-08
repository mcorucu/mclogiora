# ADR 0004: Content & Taxonomy Scope Boundaries

## Status

Accepted. Amended by ADR 0009 (fully open-source product model).

The file name is retained for ADR stability. The original title referred to "free vs add-on" boundaries, which described a commercial distinction that no longer exists. The technical scope boundary described here is unchanged; only its justification has changed, from "reserved for paid modules" to "postponed for scope and stability reasons".

## Context

mcLogiora's foundation should prepare support for standard WordPress content and taxonomy translation. WooCommerce and LMS integrations are postponed to later phases so that the core content model can stabilise first; when implemented they will be free and open source. Phase 05 must not implement real translation workflows, persistence, content creation, term creation, REST endpoints, AJAX handlers, external services, or role changes.

## Decision

Prepare read-only registries and placeholder services for:

- Posts.
- Pages.
- Public custom post types.
- Categories.
- Tags.
- Public custom taxonomies.

Explicitly exclude WooCommerce and LMS post types and taxonomies from the current foundation. Surface the exclusion in the admin UI as calm informational copy only.

Do not implement public APIs, content mutation, term mutation, meta copying, or schema in Phase 05.

## Consequences

- Future content and taxonomy translation workflows can depend on stable registry/service contracts.
- WooCommerce and LMS integrations remain outside the current scope, deferred to later free/open-source compatibility phases.
- The admin UI can communicate boundaries without upsells or remote checks, because there is nothing to sell.
- Later phases must deliberately add real translation workflows and persistence instead of inheriting hidden behavior from the foundation.
