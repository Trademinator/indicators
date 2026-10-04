# Public API inventory

This inventory is generated from reflection on the packaged PHP source. Types named
`PrecisionContext` and `PrecisionPolicy` belong to `Trademinator\BcMath`.
`Generator` is PHP's built-in generator type.

## Calculator and composition

```php
use Trademinator\Indicators\Indicators;

$calculator = new Indicators(
    precision: $context, // ?PrecisionContext, default null
    policy: $policy,     // ?PrecisionPolicy, default null
);
```

A supplied context includes its policy and takes precedence over the separate
policy argument. The class composes `Patterns`, which composes `Technical`, which
composes `TickerManipulation` and `PrecisionAware`. Direct trait consumers do not
need to implement a constructor.

The namespaced function below shares the trait normalizer implementation:

```php
Trademinator\Indicators\normalize_ticker(
    array &$tickers,
    bool $reindex = false,
    string $indexUnit = 'seconds'
): array;
```

## Return-value conventions

Array-based indicator methods mutate candles by reference. A `string` return from
those methods is the generated **column name**, not the latest numerical result;
read that column on a candle to get its value. Multi-output indicator methods
return an ordered array of column names. `rename_key()` and the ticker utilities
return arrays or generators as indicated rather than indicator column names.
Scalar `technical_*_value()` methods return decimal values (or `null` for the two
undefined-ratio helpers), and `technical_candle_geometry()` returns a value map.

The optional final `context` overrides precision for that calculation, including
nested indicators, using a clone. It does not reconfigure the caller. The
`technical_scale()` resolver instead uses the calculator's configured context or
policy. Comparison-specific explicit scales remain separate arguments.

## Precision configuration

```php
setPrecisionContext(?PrecisionContext $precision): static;

setPrecisionPolicy(PrecisionPolicy $policy): static;

withPrecisionContext(PrecisionContext $precision): static;

precisionPolicy(): PrecisionPolicy;

precisionContext(array $values): PrecisionContext;

technical_scale(string|int|float ...$values): int;
```

## Ticker normalization and streaming

```php
normalize_ticker(array &$tickers, bool $reindex = false, string $indexUnit = 'seconds'): array;

ticker_slice(array $tickers, int $period, int $multiplier = 3): array;

ticker_slide(iterable $tickers, callable $calculate, int $period, int $multiplier = 3, int $batchSize = 500, ?callable $continuous = null): Generator;

delete_key(array &$tickers, string ...$keys): void;

clone_key(array &$tickers, string $oldkey, string $newkey): array;
```

See [streaming requirements](STREAMING.md) before using retained slices.

## Moving averages and base values

```php
ema(array &$tickers, int $period = 2, string $index = 'close',
    ?PrecisionContext $context = null
): string;

smma(array &$tickers, int $period = 20, string $index = 'close',
    ?PrecisionContext $context = null
): string;

sma(array &$tickers, int $period = 20, string $index = 'close',
    ?PrecisionContext $context = null
): string;

tp(array &$tickers,
    ?PrecisionContext $context = null
): string;

tr(array &$tickers,
    ?PrecisionContext $context = null
): string;

min_max(array &$tickers, int $period = 30, string $index = 'close', ?int $decimals = null,
    ?PrecisionContext $context = null
): array;

midkey(array &$tickers, string $key1 = 'high', string $key2 = 'low',
    ?PrecisionContext $context = null
): string;
```

## Momentum, trend and volatility

```php
chop(array &$tickers, int $period = 20,
    ?PrecisionContext $context = null
): string;

ichimoku(array &$tickers, int $tenkansen = 9, int $kijunsen = 26, int $chikou = 26, int $senkou_b = 52,
    ?PrecisionContext $context = null
): array;

sto(array &$tickers, int $period1 = 14, int $period2 = 3, int $period3 = 3,
    ?PrecisionContext $context = null
): array;

sto_rsi(array &$tickers, int $period1 = 14, int $period2 = 3, int $period3 = 3,
    ?PrecisionContext $context = null
): array;

cci(array &$tickers, int $period = 20,
    ?PrecisionContext $context = null
): string;

dm(array &$tickers,
    ?PrecisionContext $context = null
): array;

gain(array &$tickers, int $period = 14,
    ?PrecisionContext $context = null
): array;

rsi(array &$tickers, int $period = 14,
    ?PrecisionContext $context = null
): string;

roc(array &$tickers, int $delay = 1, string $index = 'close',
    ?PrecisionContext $context = null
): string;

macd(array &$tickers, int $short_period = 12, int $long_period = 26, int $signal_period = 9,
    ?PrecisionContext $context = null
): array;

ppo(array &$tickers, int $short_period = 12, int $long_period = 26, int $signal_period = 9,
    ?PrecisionContext $context = null
): array;

atr(array &$tickers, int $period = 14, string $average_function = 'smma',
    ?PrecisionContext $context = null
): string;

atrp(array &$tickers, int $period = 14, string $average_function = 'smma',
    ?PrecisionContext $context = null
): string;

adx(array &$tickers, int $period = 14,
    ?PrecisionContext $context = null
): array;

ao(array &$tickers, int $short = 5, int $long = 34,
    ?PrecisionContext $context = null
): array;

ac(array &$tickers, int $period = 5,
    ?PrecisionContext $context = null
): string;

bb(array &$tickers, int $period = 20, string|int|float $stddev = 2,
    ?PrecisionContext $context = null
): array;

keltner(array &$tickers, int $period = 20, string|int|float $bandwidth = '1.5',
    ?PrecisionContext $context = null
): array;

squeeze(array &$tickers,
    ?PrecisionContext $context = null
): array;

super_trend(array &$tickers, int $period = 10, string|int|float $factor = 3,
    ?PrecisionContext $context = null
): array;
```

## Series transformations and derived features

```php
normalize(array &$tickers, string $key = 'close', string $index = 'close',
    ?PrecisionContext $context = null
): string;

percentage(array &$tickers, string $index1 = 'close', string $index2 = 'open',
    ?PrecisionContext $context = null
): string;

difference(array &$tickers, string $index1 = 'close', string $index2 = 'open',
    ?PrecisionContext $context = null
): string;

numeric_or(array &$tickers, string $index1 = 'close', string $index2 = 'open',
    ?PrecisionContext $context = null
): string;

consecutive(array &$tickers, string $index = 'close', ?int $precision = null, string|int|float|null $condition_value = null,
    ?PrecisionContext $context = null
): string;

delayed(array &$tickers, int $period = 26, string $index = 'close',
    ?PrecisionContext $context = null
): string;

rename_key(array &$tickers, string $oldkey, string $newkey,
    ?PrecisionContext $context = null
): array;

compare(array &$tickers, string $index = 'close', string $compare = 'open', ?int $digits = null,
    ?PrecisionContext $context = null
): string;

slope(array &$tickers, string $index = 'close', int $offset = 1,
    ?PrecisionContext $context = null
): array;

inflexion(array &$tickers, string $keyA = 'close', string $keyB = 'ema(2,close)',
    ?PrecisionContext $context = null
): string;

md(array &$tickers, int $period, string $key1, string $key2,
    ?PrecisionContext $context = null
): string;

price_speed(array &$tickers, int $period = 1, string|int|float|null $bar_time_in_seconds = null,
    ?PrecisionContext $context = null
): ?string;

volume_activity(array &$tickers, int $period = 20,
    ?PrecisionContext $context = null
): string;

candle_geometry(array &$tickers,
    ?PrecisionContext $context = null
): array;
```

## Scalar calculations

```php
technical_true_range_value(string|int|float $high, string|int|float $low, string|int|float|null $previousClose = null,
    ?PrecisionContext $context = null
): string;

technical_typical_price_value(string|int|float $high, string|int|float $low, string|int|float $close,
    ?PrecisionContext $context = null
): string;

technical_relative_change_value(string|int|float $current, string|int|float $base,
    ?PrecisionContext $context = null
): ?string;

technical_normalized_difference_value(string|int|float $a, string|int|float $b, string|int|float $denominator,
    ?PrecisionContext $context = null
): ?string;

technical_candle_geometry(string|int|float $open, string|int|float $high, string|int|float $low, string|int|float $close,
    ?PrecisionContext $context = null
): array;
```

## Candlestick patterns

```php
candle_anatomy(array &$tickers,
    ?PrecisionContext $context = null
): array;

is_dragonfly(array &$tickers): never;

is_grave_stone(array &$tickers): never;

is_inverted_hammer_or_shooting_star(array &$tickers,
    ?PrecisionContext $context = null
): array;
```

`is_dragonfly()` and `is_grave_stone()` deliberately throw `LogicException` because
the upstream routines were unfinished. They do not return signals.

## Important column conventions

Consume the returned column names rather than reconstructing names in application
code. For example, `ema($tickers, 14)` returns `ema(14,close)`, and `atrp($tickers,
3, 'ema')` returns `atrp(3,ema)`. `min_max()` includes `auto` in its generated names
when comparison decimals are omitted. Some inherited names, such as `dx()` and
`senkou_a()`, are not fully parameterized; do not combine incompatible calculation
parameters in the same retained cache.

See [migration notes](MIGRATION.md) for changes in precision, warm-up, zero handling,
parameter use, and formatting compared with the application traits.
