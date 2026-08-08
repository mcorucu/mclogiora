# Setup Wizard Foundation

Phase 07 makes the first setup wizard steps functional at `mcLogiora -> Setup Wizard`.

## Planned Steps

1. Welcome
2. Default Language
3. Additional Languages
4. URL Format
5. Switcher
6. Finish

## Current Behavior

The Welcome step explains the setup path.

The Default Language step can:

- Select an existing language as the default.
- Create a new active language and mark it as default.

The saved data lives in `wp_mclogiora_languages`. No setup completion option is stored.

## Future Responsibilities

Later phases can attach the remaining setup behavior once additional languages, URL decisions, and switcher architecture are implemented.

## Boundary

No setup completion state, URL setting, switcher setting, SEO setting, REST route, AJAX handler, or external service configuration is stored in Phase 07.
