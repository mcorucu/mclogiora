# Editor Architecture

Phase 09 introduces an editor-independent foundation. `EditorInterface` is the boundary used by future editor workflows, while `EditorContext` carries only normalized object and screen identity.

`EditorRegistry` stores adapters, `EditorFactory` creates the core adapter set, and `EditorManager` registers Classic Editor, Block Editor, and Elementor adapters. `EditorDetector` resolves adapters that are available in the current environment. Adapter resolution does not register hooks, enqueue scripts, open editor panels, or mutate WordPress content.

Each adapter exposes a stable identifier, a label, availability, context support, and the future UI areas it may own:

- Classic Editor: metabox.
- Block Editor: sidebar.
- Elementor: panel.

These areas are descriptive placeholders only in Phase 09. Future workflow phases must keep relation and language services behind the editor boundary so content creation and translation state do not depend on a particular editor.

Third-party adapters can be added through the `mclogiora_register_editors` filter. An adapter must remain dormant unless a later phase explicitly owns its editor hooks and assets. That filter is **not** a supported public contract: `EditorInterface` still carries the Phase 09 `get_placeholder_areas()` seam and takes an internal `EditorContext`, so freezing it would freeze both. To prepare a translation's content for a builder, use the supported `mclogiora_register_payload_adapters` filter instead. See `developer-api.md`.
