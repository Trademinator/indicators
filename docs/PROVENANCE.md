# Source provenance

Inspected on October 4, 2026 through the connected GitHub repository interface.

## Trademinator/trader-server

The default branch identified these paths and Git content-blob IDs:

| Source path | Git blob |
| --- | --- |
| `app/Traits/Technical.php` | `15b46b76dd9cab7a3f812e4663bfd733ec01781b` |
| `app/Traits/Patterns.php` | `d8856051755238819da33bcafe62d0b063e6631a` |
| `app/Traits/TickerManipulation.php` | `922199ba923321bd28f5ef55e0372c8d90aee126` |

These are source-content identifiers, not repository commit IDs. The package is a refactored extraction, not a byte-identical copy. The source Composer manifest declares MIT licensing.

## Trademinator/bcmath

The dependency's current source and `v0.1.0` function API were inspected. In particular, `bcdec` takes an array, `bcstddev` accepts an optional scale, and namespaced functions are Composer file-autoloaded under `Trademinator\BcMath`.

| Dependency source | Git blob |
| --- | --- |
| `src/functions.php` | `4c2d4fce3c266e77daa8ff45d024cc3527fe3aa3` |
| `src/PrecisionContext.php` | `3602763eda38ec0184fdb7a14547cbe908a74981` |
| `src/PrecisionPolicy.php` | `333b5c6b00eb3103a2a7e9e609a91c88fc3ad99b` |
| `src/Internal/Logarithm.php` | `0038e2a145c50338ba8d3817d450670d14d11ceb` |

No dependency implementation or compatibility `Bc` trait is distributed in this package. Composer resolves `trademinator/bcmath` when the consumer installs it.

The input-schema interpretation was checked against CCXT's official Manual, and decimal/scientific-notation handling against PHP's official BCMath manual. No exchange was contacted and no market data was downloaded during packaging.
