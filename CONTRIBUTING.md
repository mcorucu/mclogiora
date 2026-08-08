# Contributing

Thank you for helping improve mcLogiora.

## Principles

- Preserve the architecture in `PLANNING.md`.
- Keep the free plugin WordPress.org compliant.
- Do not add tracking, telemetry, hidden remote calls, or premium nagging.
- Keep external services optional and documented.
- Load only what is needed for the current request.
- Follow WordPress Coding Standards.
- Keep admin UI decisions aligned with Skylearn.

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
