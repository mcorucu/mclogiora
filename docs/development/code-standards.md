# Code Standards & Static Analysis

mcLogiora enforces WordPress Coding Standards through PHPCS and static analysis through PHPStan. Both run locally and in CI, using the same configuration.

## Running the checks

```bash
composer install
composer check
```

`composer check` is the local quality gate and runs, in order:

| Step | Command | What it does |
| --- | --- | --- |
| 1 | `composer validate --strict` | Validates `composer.json` |
| 2 | `composer lint` | `php -l` across every PHP file |
| 3 | `composer phpcs` | WordPress Coding Standards |
| 4 | `composer phpstan` | Static analysis at level 5 |

Individual steps can be run on their own (`composer phpcs`, `composer phpstan`, …). `composer phpcbf` fixes the violations that can be fixed automatically.

CI runs the same four checks, plus a repository-hygiene job. If `composer check` passes locally, CI should pass too.

## PHPUnit

There is **no test suite yet**, and PHPUnit is deliberately not installed.

`tests/` currently contains a bootstrap constant and `FoundationTest.php`, which is a placeholder class with no assertions and no PHPUnit dependency — it documents an intended first test target rather than testing anything. Installing PHPUnit now would only produce a suite that passes because it asserts nothing, which is worse than no suite at all: it would imply coverage that does not exist.

Real tests belong with the first phase that adds real behaviour. Phase 10 introduces content and taxonomy mutation workflows and is the natural place to establish PHPUnit, WordPress test fixtures, and a `composer test` script wired into `composer check`.

## PHPCS sniff scoping

WPCS is written for procedural WordPress plugins: the global `$wpdb`, direct `wp_verify_nonce()` calls, and `class-*.php` file names. mcLogiora is PSR-4 and dependency-injected, which produces systematic false positives.

The baseline audit on 2026-08-08 found 458 violations. 359 of them came from four sniff families that cannot recognise the patterns this codebase uses. Each is excluded in `phpcs.xml.dist` and **scoped to the specific files that use the pattern**, so the sniffs remain fully active everywhere else, including files added by future phases.

| Sniff | Count | Scope | Why |
| --- | --- | --- | --- |
| `WordPress.Files.FileName` | 163 | Whole project | PSR-4 names class files after their class. Mutually exclusive with `class-*.php`; PSR-4 is the decision on record (ADR 0001, `composer.json`). |
| `WordPress.DB.PreparedSQL` + `PreparedSQLPlaceholders` | 141 | 3 files | `wpdb` is constructor-injected, so the sniff cannot see `$this->wpdb->prepare()`. |
| `WordPress.Security.NonceVerification` | 42 | 2 files | Nonces are verified via `Security::verify_nonce()`; the sniff does not follow static wrappers. |
| `WordPress.Security.ValidatedSanitizedInput` | 13 | 2 files | Input is sanitized via `Validation::*`; same wrapper limitation. |

### Manual audit of the excluded security sniffs

Because these exclusions cover security-relevant sniffs, the affected files were read line by line before the exclusions were added:

**SQL** — `SchemaBuilder`, `DatabaseLanguageRepository`, `DatabaseTranslationRelationRepository`. Every user-supplied value is bound with a `%s` or `%d` placeholder through `prepare()`. The only interpolated token is the table name, which comes from `McLogiora\Database\TableNames` as `$wpdb->prefix` plus a hard-coded suffix, contains no user input, and cannot be parameterized in MySQL. No query builds SQL from request data.

**Nonces and input** — `LanguageManager`, `SetupWizard`. Both verify a nonce and check a capability before acting on request data, and route input through the `Validation` helpers.

### Risk and follow-up

These exclusions mean the affected files are not machine-checked for SQL injection, nonce verification, or input sanitization. Phase 10 adds write workflows to exactly this area, so the risk is real and increases.

Before or during Phase 10, replace the file-scoped exclusions with something that restores machine checking. Options, roughly in order of preference: add `phpcs:ignore` annotations with justifications at the specific call sites; introduce a thin `wpdb` accessor the sniff recognises; or contribute wrapper-aware configuration upstream. Whichever is chosen, no new file should be added to these exclusion lists without the same line-by-line audit.

## PHPCS warnings

Errors fail the build; warnings are reported but do not (`ignore_warnings_on_exit`). Six warnings are currently outstanding, all of them reserved-keyword parameter names in value-object constructors:

| File | Parameter |
| --- | --- |
| `src/Relations/TranslationManager.php` (×2) | `$empty` |
| `src/Relations/InMemoryTranslationRelationRepository.php` | `$new` |
| `src/Content/TranslatableContentType.php` | `$public` |
| `src/Taxonomies/TranslatableTaxonomy.php` | `$public` |
| `src/Languages/Language.php` | `$default` |

Renaming them would change public method signatures, which is an API change rather than a standards fix, so they were left alone. They are safe to fix in any phase that already touches those signatures.

## PHPStan baseline

`phpstan-baseline.neon` records two pre-existing findings so CI can block **new** ones. The baseline exists to make debt visible, not to hide it. Do not regenerate it to make an error disappear — fix the error, or discuss it first.

### 1. `DatabaseLanguageRepository::create()` may return `null` — genuine bug

```
Method McLogiora\Languages\DatabaseLanguageRepository::create()
should return McLogiora\Languages\Language|WP_Error but returns null.
```

`create()` ends with `return $this->find_by_code( $language->code() );`, and `find_by_code()` returns `Language|null`. The declared contract is `Language|WP_Error`.

In practice the row was just inserted, so the lookup normally succeeds. If it does not — a failed read, replication lag, or an unexpected database state — `create()` returns `null`. A caller written against the contract will test `instanceof WP_Error`, take the success path, and fatal on a method call against `null`.

This is a real defect, not a false positive. It was left unfixed here because RE-ENTRY R3 is a tooling phase that must not change runtime behaviour, and the fix alters an error path. **Phase 10 should fix it**, most likely by returning a `WP_Error` when the post-insert lookup fails.

### 2. `InMemoryTranslationRelationRepository::create_empty_group()` never returns `WP_Error`

```
Method ...::create_empty_group() never returns WP_Error
so it can be removed from the return type.
```

Not a defect. The in-memory implementation cannot fail, but its return type follows the interface, which database-backed implementations need. Narrowing it would break interface conformance. Left as-is deliberately.
