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

Run syntax checks before opening a pull request:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

When tooling is installed, also run PHPCS and PHPStan:

```bash
vendor/bin/phpcs
vendor/bin/phpstan analyse
```
