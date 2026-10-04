# Migration from the application traits

## Package boundary

The package includes `Patterns`, `Technical` and `TickerManipulation`, plus a shared precision trait, an optional `Indicators` calculator class and a namespaced normalization function. `Patterns` composes `Technical`; `Technical` composes `TickerManipulation` and precision configuration. The math dependency is `trademinator/bcmath:^0.1.0`.

The original application `Bc` trait is not included, and inherited `$this->bcconv()`, `$this->bcmax()` and similar convenience methods are not reintroduced. Consumers that themselves use BCMath helpers should import the appropriate functions and declare a direct `trademinator/bcmath` dependency.

```php
use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;
use function Trademinator\BcMath\bcstddev;

$value = bcconv('1e-20');
$decimals = bcdec([$value, '1.5000'], trimTrailingZeros: true);
$deviation = bcstddev($values, sample: false, scale: $context->scale);
```

The standalone `bcdec()` accepts an array, not the old trait's variadic call. `bcstddev()` replaces `stats_standard_deviation()`.

## Application integration

After installing the package, change imports to `Trademinator\Indicators\Traits\Technical`, `Patterns`, or `TickerManipulation`. To migrate application imports incrementally, an application-owned wrapper can temporarily retain the old namespace:

```php
<?php
namespace App\Traits;

trait Technical
{
    use \Trademinator\Indicators\Traits\Technical;
}
```

Equivalent wrappers can be used for `Patterns` and `TickerManipulation`. They contain no algorithm copies. Audit application callers of inherited `Bc` methods separately: an indicator wrapper does not restore those methods.

Normalize every CCXT OHLCV fetch, including each pagination/backfill response. Decide in the application whether to exclude an unfinished current candle, how to merge/deduplicate pages, and when gaps start a new contiguous sequence.

This archive does not alter Trademinator Server's Composer manifest, application traits, ingestion call sites, deployment configuration, or existing trained data. Installing the package and changing Server/Client imports are separate consumer-repository operations.

## Precision and observable differences

| Area | Package behavior |
| --- | --- |
| Global precision | The old exchange constant is not read or defined. Calculation settings come from `PrecisionPolicy`/`PrecisionContext`; no `bcscale()` changes are made. |
| Public signatures | Domain method names are retained, arguments are typed, and computation methods accept an optional final `context` argument. Invalid periods are rejected. |
| Results | Indicators retain calculation-scale decimal strings. Legacy two-place formatting and implicit eight-place arithmetic are removed. |
| Explicit comparison scales | `min_max`, `compare`, and `consecutive` accept a nullable explicit scale. Values above the configured policy maximum require raising that maximum. |
| Extrema column labels | An omitted `min_max` decimal argument appears as `auto` in generated column names, for example `min(30,close,auto)`. Explicit numeric scales still appear numerically. Always consume the returned names. |
| Scalar zero denominators | Relative-change and normalized-difference helpers return `null`, as in the source contract. |
| Indicator zero denominators | Undefined zero-denominator ratios use deterministic zero outputs rather than raising division errors. Price stochastic retains its legacy flat-window 100; stochastic RSI retains 50. |
| Zero-close ATRP | Returns zero instead of substituting a fictitious `10^-exchangeDecimals` price. |
| Bollinger warm-up | The first row gets zero standard deviation and initialized bands/bandwidth; squared tiny deltas retain extra internal precision. |
| Squeeze warm-up | The first two rows receive initialized zero outputs rather than missing columns. |
| Ichimoku parameters | The requested `chikou` delay and `kijunsen` displacement are honored; hidden hard-coded 26-bar arithmetic is removed. The source's lagged/causal column convention remains. |
| Price speed | An explicitly supplied interval is respected. Otherwise milliseconds are converted using decimal arithmetic; a zero inferred interval returns `null`. |
| Ordinary array reuse | Ordinary arrays are recalculated after input changes. Only explicitly retained slice overlap is treated as cached. |
| Native float math | Supertrend and completed pattern arithmetic/comparisons use BCMath; the original pattern formulas and stabilizer remain. |

EMA/Wilder warm-up, first-candle high-low true range, partial-window SMA, single-pass Wilder RSI, and ATR's default SMMA are preserved. The legacy DM close-price first-row seed and SMA-based ADX directional ratios are also preserved; this is not a replacement with a differently seeded textbook ADX.

Some legacy column names are not fully parameterized, such as `dx()` and `senkou_a()`. Do not mix multiple incompatible parameterizations or precision contexts into the same retained streaming cache. Rebuild the derived columns and recurrence state after changing precision, parameters, or source data. Server and Client should use the same package version and precision configuration.

Experimental dragonfly/gravestone routines remain unavailable rather than being implemented speculatively.
