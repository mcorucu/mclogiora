# ADR 0008: Editor Compatibility Foundation

## Status

Accepted

## Context

mcLogiora must support multiple WordPress editing experiences without coupling translation workflows to Gutenberg, Classic Editor, or a third-party builder. Loading editor integrations too early would add unnecessary work to normal requests and make later content workflows difficult to test.

## Decision

Introduce an `EditorInterface` with an `EditorContext`, registry, factory, manager, and detector. Register the core Classic Editor, Block Editor, and Elementor adapters through the module system. Keep each adapter limited to identity, availability, context support, and future placeholder areas in Phase 09.

Add a compatibility layer that detects editors, builders, known plugins, and the active theme through read-only metadata. Provide a read-only admin dashboard for these results. Do not register editor hooks, load editor assets, mutate content, or implement translation workflows in this phase.

## Consequences

- Future workflows can consume normalized editor contexts without knowing the editor implementation.
- Third-party adapters have a stable registration filter.
- Compatibility diagnostics remain useful without making the free core depend on third-party classes.
- Editor scripts, metaboxes, sidebars, builder parsing, and content writes remain explicit work for later phases.
