# ADR 0004: Free vs Add-on Content Boundaries

## Status

Accepted

## Context

mcLogiora's free foundation should prepare support for standard WordPress content and taxonomy translation. The project plan reserves WooCommerce and LMS integrations for future premium modules. Phase 05 must not implement real translation workflows, persistence, content creation, term creation, REST endpoints, AJAX handlers, external services, or role changes.

## Decision

Prepare read-only registries and placeholder services for:

- Posts.
- Pages.
- Public custom post types.
- Categories.
- Tags.
- Public custom taxonomies.

Explicitly exclude WooCommerce and LMS post types and taxonomies from the free foundation. Surface the exclusion in the admin UI as calm informational copy only.

Do not implement public APIs, content mutation, term mutation, meta copying, or schema in Phase 05.

## Consequences

- Future content and taxonomy translation workflows can depend on stable registry/service contracts.
- WooCommerce and LMS integrations remain outside the free core.
- The admin UI can communicate boundaries without upsells or remote checks.
- Later phases must deliberately add real translation workflows and persistence instead of inheriting hidden behavior from the foundation.
