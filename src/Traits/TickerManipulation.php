<?php

declare(strict_types=1);

namespace Trademinator\Indicators\Traits;

use Generator;
use InvalidArgumentException;
use LogicException;

use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;

trait TickerManipulation
{
    /**
     * Normalize EVERY batch fetched from CCXT before calculating indicators.
     * Input: [timestampMilliseconds, open, high, low, close, volume].
     * Keeps original milliseconds under the legacy name "microtimestamp".
     * Mutates only after all rows validate; prices are never rounded.
     *
     * @param array<int|string, array<int|string, mixed>> $tickers
     * @return array<int|string, array<string, mixed>>
     */
    public function normalize_ticker(array &$tickers, bool $reindex = false, string $indexUnit = 'seconds'): array
    {
        if (! in_array($indexUnit, ['seconds', 'milliseconds'], true)) {
            throw new InvalidArgumentException('Ticker index unit must be seconds or milliseconds.');
        }
        $normalized = [];
        foreach ($tickers as $originalKey => $ticker) {
            if (! is_array($ticker)) {
                throw new InvalidArgumentException('Each OHLCV candle must be an array.');
            }
            $timestamp = $ticker['microtimestamp'] ?? $ticker[0] ?? null;
            if ((! is_int($timestamp) && ! is_string($timestamp)) || ! ctype_digit((string) $timestamp)
                || filter_var($timestamp, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('OHLCV timestamp must be a non-negative integer in milliseconds.');
            }
            $timestamp = (int) $timestamp;
            $row = [];
            foreach ($ticker as $key => $value) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    $row[$key] = $value;
                }
            }
            $row['human_date'] = gmdate('YmdHis', intdiv($timestamp, 1000));
            $row['microtimestamp'] = $timestamp;
            foreach ([1 => 'open', 2 => 'high', 3 => 'low', 4 => 'close', 5 => 'volume'] as $offset => $name) {
                $value = $ticker[$name] ?? $ticker[$offset] ?? ($name === 'volume' ? '0' : null);
                if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    throw new InvalidArgumentException('OHLCV '.$name.' must be numeric.');
                }
                $row[$name] = bcconv($value);
            }
            // bcdec() in trademinator/bcmath takes an ARRAY, not legacy variadics.
            $scale = bcdec([$row['open'], $row['high'], $row['low'], $row['close'], $row['volume']]);
            if (bccomp($row['high'], $row['low'], $scale) < 0
                || bccomp($row['high'], $row['open'], $scale) < 0
                || bccomp($row['high'], $row['close'], $scale) < 0
                || bccomp($row['low'], $row['open'], $scale) > 0
                || bccomp($row['low'], $row['close'], $scale) > 0
                || bccomp($row['low'], '0', $scale) < 0
                || bccomp($row['volume'], '0', $scale) < 0) {
                throw new InvalidArgumentException('Invalid OHLCV values.');
            }
            $key = $reindex
                ? ($indexUnit === 'milliseconds' ? $timestamp : intdiv($timestamp, 1000))
                : $originalKey;
            if (array_key_exists($key, $normalized)) {
                throw new InvalidArgumentException('Duplicate ticker index; use milliseconds for distinct sub-second candles.');
            }
            $normalized[$key] = $row;
        }
        $tickers = $normalized;

        return $tickers;
    }

    /** Keep calculated overlap and a mature recursive boundary, preserving keys. */
    public function ticker_slice(array $tickers, int $period, int $multiplier = 3): array
    {
        $this->validate_ticker_window($period, $multiplier);
        if ($tickers === []) {
            return [];
        }
        $length = $period * $multiplier;
        $offset = max(0, count($tickers) - $length);
        $firstPosition = (int) (reset($tickers)['__ticker_position'] ?? 1);
        if ($offset > 0 && $firstPosition + $offset < $period * 2) {
            $offset = 0;
        }
        $slice = array_slice($tickers, $offset, null, true);
        $position = $firstPosition + $offset;
        $first = true;
        foreach ($slice as &$ticker) {
            unset($ticker['__ticker_seed']);
            $ticker['__ticker_position'] = $position++;
            $ticker['__ticker_cached'] = true;
            if ($first && $ticker['__ticker_position'] > 1) {
                $ticker['__ticker_seed'] = true;
            }
            $first = false;
        }
        unset($ticker);

        return $slice;
    }

    /**
     * The callback MUST accept its array by reference. Keys are chronological
     * and unique. Only new rows are emitted; bookkeeping stays internal.
     * See docs/STREAMING.md for supported indicators and overlap requirements.
     *
     * @param iterable<int|string, array<string, mixed>> $tickers
     * @param callable(array&): mixed $calculate
     * @param null|callable(array, array): bool $continuous
     * @return Generator<int|string, array<string, mixed>>
     */
    public function ticker_slide(iterable $tickers, callable $calculate, int $period, int $multiplier = 3, int $batchSize = 500, ?callable $continuous = null): Generator
    {
        $this->validate_ticker_window($period, $multiplier);
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Ticker batch size must be positive.');
        }
        $pending = $carry = [];
        $position = 0;
        $previous = null;
        $previousKey = null;
        $flush = function () use (&$pending, &$carry, $calculate, $period, $multiplier): Generator {
            $newKeys = array_keys($pending);
            $slice = $carry + $pending;
            $calculate($slice);
            if (array_keys($slice) !== array_keys($carry + $pending)) {
                throw new LogicException('Ticker calculation must preserve the slice keys and order.');
            }
            $carry = $this->ticker_slice($slice, $period, $multiplier);
            $pending = [];
            foreach ($newKeys as $key) {
                $row = $slice[$key];
                unset($row['__ticker_position'], $row['__ticker_seed'], $row['__ticker_cached']);
                yield $key => $row;
            }
        };
        foreach ($tickers as $key => $ticker) {
            if (! is_array($ticker)) {
                throw new InvalidArgumentException('Each ticker must be an array.');
            }
            if (array_key_exists($key, $pending) || array_key_exists($key, $carry)
                || (is_int($key) && is_int($previousKey) && $key <= $previousKey)) {
                throw new InvalidArgumentException('Ticker slices require unique, ascending keys.');
            }
            if ($previous !== null && $continuous !== null && ! $continuous($previous, $ticker)) {
                if ($pending !== []) {
                    yield from $flush();
                }
                $carry = [];
                $position = 0;
            }
            unset($ticker['__ticker_seed'], $ticker['__ticker_cached']);
            $ticker['__ticker_position'] = ++$position;
            $pending[$key] = $ticker;
            $previous = $ticker;
            $previousKey = $key;
            if (count($pending) === $batchSize) {
                yield from $flush();
            }
        }
        if ($pending !== []) {
            yield from $flush();
        }
    }

    public function delete_key(array &$tickers, string ...$keys): void
    {
        foreach ($tickers as &$ticker) {
            foreach ($keys as $key) {
                unset($ticker[$key]);
            }
        }
        unset($ticker);
    }

    public function clone_key(array &$tickers, string $oldkey, string $newkey): array
    {
        foreach ($tickers as &$ticker) {
            $ticker[$newkey] = $ticker[$oldkey];
        }
        unset($ticker);

        return $tickers;
    }

    private function ticker_cached(array $ticker, string $key): bool
    {
        return ($ticker['__ticker_cached'] ?? false) && array_key_exists($key, $ticker);
    }

    private function ticker_seed(array $tickers, array $keys, int $warmup): ?array
    {
        $first = reset($tickers);
        if ($first === false || ! ($first['__ticker_seed'] ?? false)) {
            return null;
        }
        foreach ($keys as $key) {
            if (! isset($first[$key]) || ! is_numeric($first[$key]) || $first['__ticker_position'] < $warmup) {
                throw new LogicException('Missing mature '.$key.' slice seed. Use ticker_slide() on complete history before taking its last slice.');
            }
        }

        return $first;
    }

    private function ticker_require_history(array $tickers, int $lookback): void
    {
        $first = reset($tickers);
        if ($first === false || ! ($first['__ticker_seed'] ?? false)) {
            return;
        }
        $available = 0;
        foreach ($tickers as $ticker) {
            if (! ($ticker['__ticker_cached'] ?? false)) {
                if ($available < $lookback) {
                    throw new LogicException('Ticker overlap is shorter than the indicator lookback; increase the slice period or multiplier.');
                }

                return;
            }
            $available++;
        }
    }

    private function validate_ticker_window(int $period, int $multiplier): void
    {
        if ($period < 1 || $multiplier < 1 || $multiplier > PHP_INT_MAX - 2
            || $period > intdiv(PHP_INT_MAX, $multiplier + 2)) {
            throw new InvalidArgumentException('Ticker period and multiplier must be positive and fit an integer window size.');
        }
    }
}
