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

Phase 10 established the test suite. Run it with `composer test`, or as part of `composer check`.

The workflow and domain layers contain no direct WordPress calls, so they are tested without a WordPress installation. `tests/bootstrap.php` stubs the few primitives they use — `WP_Error`, the translation functions, and a handful of sanitizers. These stubs are minimal and faithful; they are not a WordPress emulator, and tests must not rely on behaviour beyond what the bootstrap defines.

Test doubles live in `tests/Support/`:

| Double | Replaces |
| --- | --- |
| `FakeContentGateway` | The WordPress post and term APIs, with injectable failures |
| `FakeRelationRepository` | Relation persistence, enforcing the same integrity rules as the database repository |
| `FakeLanguageService` | A fixed language set |
| `FakeWpdb` | Enough of `wpdb` to test repository contracts, including read-back failure |
| `WorkflowTestFactory` | Assembles the real workflow classes over those doubles |

Only the WordPress and database edges are replaced; the classes under test are the production ones, wired as production wires them.

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

### Phase 10 attempt and why it was reverted

Phase 10 tried to remove `src/Languages/DatabaseLanguageRepository.php` from the SQL exclusion lists. Assigning a local `$wpdb` before each query does work for PHPCS: the sniff then sees `prepare()` normally, and the remaining table-name interpolation needs only a per-query `phpcs:ignore`. That took the file from 33 errors to zero.

It was reverted because the alias changes how PHPStan resolves those queries and reintroduces static-analysis errors. Trading one tool's correctness for another's is not an improvement, and forcing it through inside a feature phase would have meant baselining new PHPStan errors to fix PHPCS ones.

Converting these files needs a change that satisfies both tools, verified against both, in its own commit. That is the recommended shape of the follow-up below.

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

`phpstan-baseline.neon` records one pre-existing finding so CI can block **new** ones. The baseline exists to make debt visible, not to hide it. Do not regenerate it to make an error disappear — fix the error, or discuss it first.

### Resolved in Phase 10: `DatabaseLanguageRepository::create()` may return `null`

```
Method McLogiora\Languages\DatabaseLanguageRepository::create()
should return McLogiora\Languages\Language|WP_Error but returns null.
```

`create()` ends with `return $this->find_by_code( $language->code() );`, and `find_by_code()` returns `Language|null`. The declared contract is `Language|WP_Error`.

In practice the row was just inserted, so the lookup normally succeeds. If it does not — a failed read, replication lag, or an unexpected database state — `create()` returns `null`. A caller written against the contract will test `instanceof WP_Error`, take the success path, and fatal on a method call against `null`.

**Fixed in Phase 10.** `create()` now returns a `WP_Error` with the code `mclogiora_language_created_but_unreadable` when the post-insert lookup fails, and the baseline entry was removed. `DatabaseLanguageRepositoryCreateTest` covers the unreadable, failed-insert, and success paths.

The read-back is performed through a small private `read_back_created_language()` method rather than calling `find_by_code()` a second time directly. The two lookups run against different database states, and separate call sites make that explicit to readers and keep static analysis from treating them as one memoized expression.

### Remaining: `InMemoryTranslationRelationRepository::create_empty_group()` never returns `WP_Error`

```
Method ...::create_empty_group() never returns WP_Error
so it can be removed from the return type.
```

Not a defect. The in-memory implementation cannot fail, but its return type follows the interface, which database-backed implementations need. Narrowing it would break interface conformance. Left as-is deliberately.
