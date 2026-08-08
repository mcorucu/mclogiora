# Detection Strategy

Detection is deliberately conservative and read-only.

## Editors

The Block Editor and Classic Editor adapters use WordPress core function availability as their runtime boundary. Elementor uses its active plugin basename rather than loading Elementor classes during core boot. `EditorDetector` returns only adapters that report availability.

## Builders

`BuilderDetector` checks known plugin basenames and selected theme identifiers. It returns labels and identifiers for diagnostics only. It does not instantiate builder code, parse builder data, copy layouts, or register builder UI.

## Plugins

`PluginDetector` reads active plugin and network-active plugin basenames and reports a small known set relevant to future compatibility. Unknown plugins are not treated as supported integrations.

## Themes

`ThemeDetector` reads the active theme name, stylesheet identifier, and version through `wp_get_theme()`. Failure to detect a theme returns an informational fallback rather than throwing an error.

All detectors must remain safe when called before a particular WordPress API is available. No detector performs writes, schedules events, sends HTTP requests, or enqueues assets.
