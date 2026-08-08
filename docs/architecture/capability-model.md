# Capability Model

Phase 03 defines planned mcLogiora capabilities without modifying WordPress roles.

## Planned Capabilities

- `manage_mclogiora`
- `manage_mclogiora_languages`
- `manage_mclogiora_translations`
- `manage_mclogiora_settings`

## Current Resolution

`CapabilityRegistry` resolves planned capabilities to `manage_options` for admin page access during the foundation phase.

This avoids role mutation during Phase 03 while preserving the future capability names in code. Role mapping should be implemented in a later phase only after the activation, migration, and uninstall behavior is explicitly reviewed.

## Extension Point

The `mclogiora_resolved_capability` filter can adjust effective capabilities in development or future integrations.

## Boundary

No roles are changed. No capabilities are added to administrators, editors, or custom roles in Phase 03.
