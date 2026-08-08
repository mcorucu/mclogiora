# Compatibility Layer

`CompatibilityService` provides one read-only snapshot for the admin compatibility dashboard. It combines:

- `EditorDetector` for registered editor adapters.
- `BuilderDetector` for known builder metadata.
- `PluginDetector` for known active integration plugins.
- `ThemeDetector` for the active theme identity.

Detection reads WordPress runtime metadata only. It does not load third-party integration classes, change plugin or theme state, call remote services, or register compatibility hooks.

The compatibility screen is intentionally diagnostic. It shows the environment and the future editor surfaces, but it does not expose translation actions or content workflows. Builder detection is metadata only and must not be confused with a builder adapter implementation.

Future integrations should be added behind the existing detector and adapter contracts. They should be conditional, capability-aware in admin contexts, and responsible for their own narrowly scoped assets only when a later workflow requires them.
