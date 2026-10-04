# trademinator/indicators

Framework-independent technical indicators, candlestick patterns and ticker utilities for **CCXT OHLCV data**, using `trademinator/bcmath` and PHP's native BCMath extension.

PHP 8.2 or newer is required. Modern millisecond timestamps require a 64-bit PHP build. Laravel and CCXT are **not runtime dependencies**: Laravel can inject the calculator, and CCXT can supply the input arrays, but the library does not fetch data or resolve application configuration.

## From this source checkout

```sh
composer install
composer validate --strict
composer lint
composer test
```

The archive does not include `vendor/` or a library lockfile. A native-runtime CI workflow is included for PHP 8.2, 8.3, 8.4 and 8.5. See [validation status](docs/VALIDATION.md) for what was actually run before packaging.

After publishing the package, consumers can install it with:

```sh
composer require trademinator/indicators
```

Before publication, a consumer can use a sibling source directory:

```sh
composer config repositories.trademinator-indicators path ../trademinator-indicators
composer require 'trademinator/indicators:*@dev'
```

## Normalize every CCXT OHLCV fetch

CCXT OHLCV rows have this positional shape:

```text
[timestampMilliseconds, open, high, low, close, volume]
```

Call `normalize_ticker()` immediately after **each** fetch, including every fetched page, backfill batch, retry result or refreshed batch. The package does not intercept arbitrary CCXT calls on your behalf.

```php
use Trademinator\Indicators\Indicators;
use function Trademinator\Indicators\normalize_ticker;

// $exchange is your application's CCXT exchange instance.
$tickers = $exchange->fetch_ohlcv('BTC/USD', '1m', null, 100);
normalize_ticker($tickers, reindex: true, indexUnit: 'milliseconds');

$indicators = new Indicators();
$emaKey = $indicators->ema($tickers, 14);
$rsiKey = $indicators->rsi($tickers, 14);
$atrpKey = $indicators->atrp($tickers, 14);

$last = end($tickers);
echo $last[$emaKey];
```

Both the function and the trait method mutate the array by reference and return it. They share exactly one implementation. The default `reindex: false` preserves input keys. With reindexing enabled, the default index unit is seconds; use milliseconds when sub-second timestamps must remain distinct.

Normalized rows contain `open`, `high`, `low`, `close`, and `volume` as decimal strings. `human_date` is a UTC `YmdHis` string. The legacy `microtimestamp` field is preserved and contains **milliseconds, not microseconds**. Named metadata columns survive normalization; numeric CCXT columns are replaced with names.

Normalization expands scientific notation, validates OHLCV relationships at the represented input precision, and performs no exchange rounding. A missing/null volume retains the legacy zero default. Invalid batches leave the original array unchanged. Normalization does not sort candles, fill gaps, remove an unfinished current candle, or recover precision already lost before a value arrived as a PHP float. Those ingestion decisions belong to the application.

## Use the traits directly

```php
use Trademinator\Indicators\Traits\Patterns;

final class MyAnalyzer
{
    use Patterns; // Includes Technical, which includes TickerManipulation.
}
```

The independently usable traits are:

```text
Trademinator\Indicators\Traits\Patterns
Trademinator\Indicators\Traits\Technical
Trademinator\Indicators\Traits\TickerManipulation
```

`Technical.php` is the spelling used by the source repository. No `Bc` trait is copied into this package. Arithmetic helpers are imported with `use function Trademinator\BcMath\...`; precision types are imported from `Trademinator\BcMath`.

## Precision without application globals

There are no runtime references to `EXCHANGE_ROUND_DECIMALS`, no environment/configuration lookups, no Laravel helpers, no global debug output, and no changes to the process-wide `bcscale()` setting in this package's source.

By default, the calculator uses `PrecisionPolicy` from `trademinator/bcmath`: minimum scale 16, four guard digits, maximum scale 32, and insignificant trailing zeros ignored. Calculation precision adapts to relevant inputs, with recurrence state preserved without repeatedly adding guard digits. Inputs finer than the policy's cap require a higher `maximumScale`.

Calculation precision is **not** an exchange's order precision or a display-decimal setting. Results remain decimal strings; formatting and order quantization belong at their respective application boundaries.

### Reuse a resolved context

```php
use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Indicators;

$policy = new PrecisionPolicy(
    minimumScale: 16,
    guardDigits: 4,
    maximumScale: 48,
);

// An explicit working scale chosen by the application for this stream.
$context = new PrecisionContext(scale: 24, policy: $policy);
$indicators = new Indicators(precision: $context);

$indicators->ema($tickers, 14);
$indicators->rsi($tickers, 14);
$indicators->atrp($tickers, 14);
```

A supplied context makes `technical_scale()` an O(1) scale lookup instead of resolving decimal precision from its inputs. A context can also be resolved once with `PrecisionContext::fromValues($representativeValues, $policy)` or `PrecisionContext::forPrices(...)`. Include the finest relevant price/volume precision; a context is deliberately fixed and will not silently expand when a later batch introduces more digits. Keep it stable across all slices of the same calculation.

A context can be passed to an individual calculation as the optional final argument:

```php
$calculator = new Indicators();
$key = $calculator->ema($tickers, 14, context: $context);
```

A per-call override calculates using a clone. It does not change the original calculator. Nested indicator calls reuse the clone's context. Trait consumers can use `setPrecisionPolicy()`, `setPrecisionContext()` and `withPrecisionContext()` without adopting a constructor. Explicit comparison precision arguments to `compare()`, `consecutive()` and `min_max()` remain available and take precedence for those comparisons.

Bollinger standard deviation temporarily uses twice the resolved scale for squared deltas, then publishes at the resolved scale; otherwise tiny nonzero variance can disappear during squaring. Very low explicit scales can be inappropriate for ratio/logarithmic indicators; choppiness rejects a scale that cannot represent `log10(period)`.

## Laravel

Use the same `Indicators` class and traits. An optional, application-owned service provider is shown in [examples/IndicatorServiceProvider.php](examples/IndicatorServiceProvider.php). Configuration is resolved **in the application**, not in the library. The provider uses `bind`, not a shared mutable singleton; per-market fixed contexts can then be supplied by the application.

No provider or facade is auto-registered, and `illuminate/*` is not required.

## Indicators and migration

The existing domain method names and by-reference array style are retained. See [the API inventory](docs/API.md), [migration notes](docs/MIGRATION.md), and [streaming requirements](docs/STREAMING.md).

This extraction intentionally changes precision and removes presentation truncation. It also handles empty/zero inputs and previously undefined warm-up outputs. It is not a promise of byte-for-byte output compatibility with the old eight-decimal implementation. The migration guide identifies these differences.

`is_dragonfly()` and `is_grave_stone()` remain explicitly quarantined with `LogicException`; they were unfinished in the source. Existing pattern heuristics, including their legacy `0.001` stabilizer, are retained rather than presented as a new pattern-definition standard. `inflexion()` is retrospective and may mark an earlier row after seeing the next one.

## Examples

`examples/basic.php` is deterministic and needs no exchange connection. `examples/ccxt.php` shows the CCXT ingestion boundary. `examples/IndicatorServiceProvider.php` is an optional Laravel integration example.

## License and provenance

MIT. Derived from the Trademinator Server traits. See [source provenance](docs/PROVENANCE.md). This source archive has not been pushed, tagged, or published to a package registry.
