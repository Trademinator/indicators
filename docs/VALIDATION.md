# Validation status

Prepared on October 4, 2026. This report distinguishes completed checks from
validation that still requires the actual dependency/runtime environment.

## Completed checks

| Check | Result | Scope |
| --- | --- | --- |
| PHP syntax | Passed: 11 PHP files | Native PHP CLI 8.4.23 lint of all source, test and example files. No arithmetic is executed by lint. |
| Development regression suite | Passed: 257 tests, zero failures | PHP execution using an external, development-only Python Decimal arithmetic backend in place of the unavailable native BCMath extension. |
| Deterministic basic example | Passed with the reference backend | No exchange or Laravel connection. |
| API inventory | Generated from reflection | All public packaged trait methods are listed in API.md. |
| Archive contents | Checked before delivery | No vendor directory, lockfile, application secrets, development backend or bundled BCMath package. |

The development regression checks cover normalization and atomic failure,
scientific-notation inputs, OHLCV validation, tiny values, selected numerical
golden cases, all completed indicator entry points on normal/tiny/zero/empty data,
precision configuration and per-call isolation, streaming/complete-history parity
for the tested pipelines, recurrence boundaries, and independence from the former
exchange constant and process-wide scale.

**These 257 checks are not a native BCMath test result.** The execution environment
had no native `ext-bcmath` and no Composer executable. The inspected dependency's
function/class bodies were locally reconstructed for development checks, and
BCMath operations were supplied by a separate Python Decimal reference process.
This helps exercise PHP control flow, input handling and decimal algorithms, but
it does not replace installing the actual dependency and executing with native
BCMath. Exact parity with every upstream application indicator was not asserted;
intentional behavior differences are documented in MIGRATION.md.

Neither that temporary dependency reconstruction nor the Decimal backend is
included in this package. The shipped regression runner normally requires native
`ext-bcmath`; it labels a development reference run explicitly when the external
test harness is present. Production source uses the genuine Composer dependency
and native BCMath functions only.

## Not executed in this environment

Composer dependency resolution/install and `composer validate --strict` were not
run. Native BCMath arithmetic, the PHP 8.2/8.3/8.4/8.5 matrix, live CCXT exchange
fetches, Laravel container integration and Trademinator Server/Client integration
suites were also not run. The package was not remotely pushed, tagged or published.

The included GitHub Actions workflow defines the native version-matrix checks;
its presence is not evidence that those jobs have already passed.

## Native verification before tagging

Run from this package's source directory on a supported PHP installation with
BCMath and ctype enabled:

```sh
composer install
composer validate --strict
composer lint
composer test
php examples/basic.php
```

Then run the consuming application's suite after changing its imports and CCXT
normalization boundaries. Exercise real exchange pagination, partial-current-candle
policy, gaps, stored column names and recurrence-cache invalidation in that
application. Laravel consumers should separately exercise their container binding.

A successful native test run should report the native BCMath backend and all tests
passing. Treat a dependency install error or any test failure as a release blocker,
not as an optional warning.
