# Calculating over bounded, overlapping slices

`normalize_ticker()` converts raw CCXT batches into the named input schema. `ticker_slide()` works on a chronological iterable of those already normalized rows. It does not fetch, normalize, reorder, fill, or deduplicate data itself.

```php
$calculator = new \Trademinator\Indicators\Indicators($context);

$output = $calculator->ticker_slide(
    $normalizedRows,
    function (array &$slice) use ($calculator): void {
        $calculator->ema($slice, 14);
        $calculator->rsi($slice, 14);
        $calculator->atrp($slice, 14);
    },
    period: 14,
    multiplier: 3,
    batchSize: 500,
);

foreach ($output as $timestamp => $row) {
    // Persist or consume one newly calculated row.
}
```

The callback must accept its array **by reference** and preserve keys/order. Input keys must be unique and chronological. Prefer millisecond integer keys from normalization. Integer keys are checked for strict ascending order; arbitrary string keys are the caller's responsibility beyond the overlap duplicate checks. The stream's current batch must be closed/immutable according to the consumer's data policy.

The overlap retains calculated recurrence seeds, rather than pretending that re-seeding EMA/RSI from a few raw bars is exact. Recursive source columns are kept internally as well as public indicator aliases. Emitted rows have the `__ticker_position`, `__ticker_seed` and `__ticker_cached` bookkeeping removed.

Choose `period` at least as large as the largest indicator period or delay in the callback, including nested calculations. For example, a callback using `ac()` also invokes the longer AO average; `squeeze()` also calculates its own bands and ATR. Use enough overlap for every nested lookback. The package rejects missing mature recursive seeds and insufficient retained history when those checks apply. Warm-up can retain more than `period * multiplier` rows before safe trimming.

A `continuous` callback can detect gaps or other segment boundaries:

```php
continuous: static fn (array $previous, array $next): bool =>
    $next['microtimestamp'] - $previous['microtimestamp'] === 60_000
```

When it returns false, pending rows are emitted and recurrence/overlap state resets before the new segment. Normalization itself does not infer continuity or exchange timeframes.

Keep one stable precision context and one set of indicator parameters across the stream. Do not reuse computed overlap after changing source values, algorithms, or precision; recompute from complete history instead. Without a fixed context, the default adaptive behavior is causal with respect to the relevant inputs/state, but an externally resolved context based on future prices is still a caller-chosen precision policy.

The regression suite covers core indicator pipeline equivalence under adaptive and fixed precision, and a fixed-precision composite pipeline including MACD, PPO, stochastic, Bollinger, Keltner, Supertrend, ADX, choppiness, AO, AC, squeeze, extrema and Ichimoku. See VALIDATION.md for the distinction between reference-backend checks and pending native-runtime tests.

`inflexion()` is a retrospective full-history annotation and may change a previous row. Do not use it with a streaming consumer that has already irrevocably emitted that row. Arbitrary future callbacks are not guaranteed to be stream-safe merely because `ticker_slide()` accepts them.
