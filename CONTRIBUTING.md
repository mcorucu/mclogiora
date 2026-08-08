# Contributing

Thank you for helping improve mcLogiora.

## Principles

- Preserve the architecture in `PLANNING.md`.
- Keep the plugin WordPress.org compliant.
- mcLogiora is permanently free and open source. Do not add licence checks, feature gates, edition flags, or upgrade prompts. See `docs/adr/0009-fully-open-source-product-model.md`.
- Do not add tracking, telemetry, hidden remote calls, or premium nagging.
- Keep external services optional and documented.
- Load only what is needed for the current request.
- Follow WordPress Coding Standards.
- Keep admin UI decisions aligned with Skylearn. The original design authority file is currently unavailable; read `docs/design/README.md` before making visual changes.
- Follow the branch and review protocol in `docs/development/git-workflow.md`.

## Local Checks

Before opening a pull request:

```bash
composer install
composer check
```

`composer check` runs the full quality gate: `composer validate --strict`, a PHP syntax check across the source tree, PHPCS against WordPress Coding Standards, and PHPStan at level 5.

CI runs the same checks and must pass before a pull request can be merged.

Useful individual commands:

```bash
composer lint      # syntax only
composer phpcs     # coding standards
composer phpcbf    # auto-fix coding standards
composer phpstan   # static analysis
```

There is no test suite yet, and PHPUnit is intentionally not installed — see `docs/development/code-standards.md`, which also documents the PHPCS sniff scoping and the PHPStan baseline.
