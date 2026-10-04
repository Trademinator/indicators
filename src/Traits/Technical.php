<?php

declare(strict_types=1);

namespace Trademinator\Indicators\Traits;

use InvalidArgumentException;
use Trademinator\BcMath\PrecisionContext;

use function Trademinator\BcMath\bcabs;
use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;
use function Trademinator\BcMath\bclog10;
use function Trademinator\BcMath\bcmax;
use function Trademinator\BcMath\bcmin;
use function Trademinator\BcMath\bcstddev;

/**
 * Indicators over normalized, chronological CCXT OHLCV rows.
 * Methods mutate rows by reference and return their generated column name(s).
 * Display formatting and exchange order precision belong to the caller.
 */
trait Technical
{
    use PrecisionAware;
    use TickerManipulation;

    public function normalize(array &$tickers, string $key = 'close', string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->normalize($tickers, $key, $index);
        }
        $result = 'normalized('.$key.','.$index.')';
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $result)) {
                continue;
            }
            $scale = $this->technical_scale($row[$key], $row[$index]);
            $row[$result] = bccomp(bcconv($row[$index]), '0', $scale) > 0
                ? bcdiv(bcconv($row[$key]), bcconv($row[$index]), $scale)
                : bcadd('0', '0', $scale);
        }
        unset($row);

        return $result;
    }

    public function technical_true_range_value(int|float|string $high, int|float|string $low, int|float|string|null $previousClose = null, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->technical_true_range_value($high, $low, $previousClose);
        }
        $high = bcconv($high);
        $low = bcconv($low);
        $previousClose = $previousClose === null ? $low : bcconv($previousClose);
        $scale = $this->technical_scale($high, $low, $previousClose);

        return bcmax(
            bcsub($high, $low, $scale),
            bcabs(bcsub($high, $previousClose, $scale)),
            bcabs(bcsub($previousClose, $low, $scale)),
        );
    }

    public function technical_typical_price_value(int|float|string $high, int|float|string $low, int|float|string $close, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->technical_typical_price_value($high, $low, $close);
        }
        $high = bcconv($high);
        $low = bcconv($low);
        $close = bcconv($close);
        $scale = $this->technical_scale($high, $low, $close);

        return bcdiv(bcadd(bcadd($high, $low, $scale), $close, $scale), '3', $scale);
    }

    public function technical_relative_change_value(int|float|string $current, int|float|string $base, ?PrecisionContext $context = null): ?string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->technical_relative_change_value($current, $base);
        }
        $current = bcconv($current);
        $base = bcconv($base);
        $scale = $this->technical_scale($current, $base);

        return bccomp($base, '0', $scale) === 0
            ? null : bcdiv(bcsub($current, $base, $scale), $base, $scale);
    }

    public function technical_normalized_difference_value(int|float|string $a, int|float|string $b, int|float|string $denominator, ?PrecisionContext $context = null): ?string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->technical_normalized_difference_value($a, $b, $denominator);
        }
        $a = bcconv($a);
        $b = bcconv($b);
        $denominator = bcconv($denominator);
        $scale = $this->technical_scale($a, $b, $denominator);

        return bccomp($denominator, '0', $scale) === 0
            ? null : bcdiv(bcsub($a, $b, $scale), $denominator, $scale);
    }

    public function technical_candle_geometry(int|float|string $open, int|float|string $high, int|float|string $low, int|float|string $close, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->technical_candle_geometry($open, $high, $low, $close);
        }
        [$open, $high, $low, $close] = array_map(bcconv(...), [$open, $high, $low, $close]);
        $scale = $this->technical_scale($open, $high, $low, $close);
        $range = bcsub($high, $low, $scale);
        if (bccomp($range, '0', $scale) === 0) {
            return array_fill_keys(['body', 'upper_wick', 'lower_wick'], bcadd('0', '0', $scale));
        }

        return [
            'body' => bcdiv(bcabs(bcsub($close, $open, $scale)), $range, $scale),
            'upper_wick' => bcdiv(bcsub($high, bcmax($open, $close), $scale), $range, $scale),
            'lower_wick' => bcdiv(bcsub(bcmin($open, $close), $low, $scale), $range, $scale),
        ];
    }

    public function ema(array &$tickers, int $period = 2, string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->ema($tickers, $period, $index);
        }
        return $this->recursive_average($tickers, $period, $index, 'ema');
    }

    public function smma(array &$tickers, int $period = 20, string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->smma($tickers, $period, $index);
        }
        return $this->recursive_average($tickers, $period, $index, 'smma');
    }

    /** Full-period SMA seed, then one EMA/Wilder recurrence; no repeated re-seeding. */
    private function recursive_average(array &$tickers, int $period, string $index, string $method): string
    {
        $this->require_period($period);
        $key = $method.'('.$period.','.$index.')';
        if ($period === 1) {
            foreach ($tickers as &$row) {
                $row[$key] = bcconv($row[$index]);
            }
            unset($row);

            return $key;
        }
        $seed = $this->ticker_seed($tickers, [$key], $period);
        $count = $seed === null ? 0 : $seed['__ticker_position'] - 1;
        $previous = $sum = '0';
        $alphas = [];
        $divisor = bcadd((string) $period, '1', 0);
        $weight = bcsub((string) $period, '1', 0);
        foreach ($tickers as &$row) {
            $count++;
            if ($seed !== null && $this->ticker_cached($row, $key)) {
                $previous = (string) $row[$key];
                continue;
            }
            $value = bcconv($row[$index]);
            $scale = $this->recurrence_scale($value, $count <= $period ? $sum : $previous);
            if ($count <= $period) {
                $sum = bcadd($sum, $value, $scale);
                $row[$key] = bcdiv($sum, (string) $count, $scale);
            } elseif ($method === 'ema') {
                $alpha = $alphas[$scale] ??= bcdiv('2', $divisor, $scale);
                $row[$key] = bcadd($previous, bcmul($alpha, bcsub($value, $previous, $scale), $scale), $scale);
            } else {
                $row[$key] = bcdiv(bcadd(bcmul($previous, $weight, $scale), $value, $scale), (string) $period, $scale);
            }
            $previous = $row[$key];
        }
        unset($row);

        return $key;
    }

    public function sma(array &$tickers, int $period = 20, string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->sma($tickers, $period, $index);
        }
        $this->require_period($period);
        $this->ticker_require_history($tickers, $period - 1);
        $key = 'sma('.$period.','.$index.')';
        $sumScale = $this->technical_scale();
        $buffer = [];
        $position = 0;
        $sum = '0';
        foreach ($tickers as &$row) {
            $value = bcconv($row[$index]);
            $sumScale = $this->accumulator_scale($sumScale, $value);
            if (count($buffer) === $period) {
                $sum = bcsub($sum, $buffer[$position], $sumScale);
            }
            $buffer[$position] = $value;
            $position = ($position + 1) % $period;
            $sum = bcadd($sum, $value, $sumScale);
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = bcdiv($sum, (string) count($buffer), $this->technical_scale($sum));
            }
        }
        unset($row);

        return $key;
    }

    public function tp(array &$tickers, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->tp($tickers);
        }
        $key = 'tp()';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = $this->technical_typical_price_value($row['high'], $row['low'], $row['close']);
            }
        }
        unset($row);

        return $key;
    }

    public function tr(array &$tickers, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->tr($tickers);
        }
        $key = 'tr()';
        $this->ticker_seed($tickers, [$key], 1);
        $previousClose = null;
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = $this->technical_true_range_value($row['high'], $row['low'], $previousClose);
            }
            $previousClose = $row['close'];
        }
        unset($row);

        return $key;
    }

    public function min_max(array &$tickers, int $period = 30, string $index = 'close', ?int $decimals = null, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->min_max($tickers, $period, $index, $decimals);
        }
        $this->require_period($period);
        $this->require_scale($decimals);
        $this->ticker_require_history($tickers, $period - 1);
        $label = $decimals ?? 'auto';
        $min = 'min('.$period.','.$index.','.$label.')';
        $max = 'max('.$period.','.$index.','.$label.')';
        $absmin = 'absmin('.$index.','.$label.')';
        $absmax = 'absmax('.$index.','.$label.')';
        $stepsmin = 'steps('.$min.')';
        $stepsmax = 'steps('.$max.')';
        $absstepsmin = 'abssteps('.$absmin.')';
        $absstepsmax = 'abssteps('.$absmax.')';
        $buffer = [];
        $previous = null;
        foreach ($tickers as &$row) {
            $value = bcconv($row[$index]);
            array_unshift($buffer, $value);
            if (count($buffer) > $period) {
                array_pop($buffer);
            }
            if ($this->ticker_cached($row, $min) && $this->ticker_cached($row, $max)) {
                $previous = $row;
                continue;
            }
            $row[$min] = bcmin(...$buffer);
            $row[$max] = bcmax(...$buffer);
            $row[$absmin] = $previous === null ? $value : bcmin($previous[$absmin], $value);
            $row[$absmax] = $previous === null ? $value : bcmax($previous[$absmax], $value);
            $scale = $decimals ?? $this->technical_scale(...$buffer);
            $row[$stepsmin] = $row[$stepsmax] = $row[$absstepsmin] = $row[$absstepsmax] = 0;
            if ($previous !== null) {
                // Keep the legacy step-count convention (relative to the rolling extrema).
                if (bccomp($value, $row[$max], $scale) < 0) {
                    $row[$absstepsmax] = $previous[$absstepsmax] + 1;
                }
                if (bccomp($value, $row[$min], $scale) > 0) {
                    $row[$absstepsmin] = $previous[$absstepsmin] + 1;
                }
                foreach ([$min => $stepsmin, $max => $stepsmax] as $extreme => $steps) {
                    for ($i = 1; $i < count($buffer); $i++) {
                        if (bccomp($row[$extreme], $buffer[$i], $scale) === 0) {
                            $row[$steps] = $i;
                            break;
                        }
                    }
                }
            }
            $previous = $row;
        }
        unset($row);

        return [$min, $max, $stepsmin, $stepsmax, $absmin, $absmax, $absstepsmin, $absstepsmax];
    }

    public function chop(array &$tickers, int $period = 20, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->chop($tickers, $period);
        }
        if ($period < 2) {
            throw new InvalidArgumentException('Choppiness period must be at least two.');
        }
        $tr = $this->tr($tickers);
        [, $high] = $this->min_max($tickers, $period, 'high');
        [$low] = $this->min_max($tickers, $period, 'low');
        $key = 'chop('.$period.')';
        $buffer = $logs = [];
        foreach ($tickers as &$row) {
            $buffer[] = $row[$tr];
            if (count($buffer) > $period) {
                array_shift($buffer);
            }
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$high], $row[$low], ...$buffer);
            $span = bcsub($row[$high], $row[$low], $scale);
            $sum = '0';
            foreach ($buffer as $value) {
                $sum = bcadd($sum, $value, $scale);
            }
            $row[$key] = bcadd('0', '0', $scale);
            if (bccomp($span, '0', $scale) > 0 && bccomp($sum, '0', $scale) > 0) {
                $log = $logs[$scale] ??= bclog10($period, $scale);
                if (bccomp($log, '0', $scale) === 0) {
                    throw new InvalidArgumentException('Choppiness precision cannot represent log10(period); increase the context scale.');
                }
                $row[$key] = bcmul('100', bcdiv(bclog10(bcdiv($sum, $span, $scale), $scale), $log, $scale), $scale);
            }
        }
        unset($row);

        return $key;
    }

    public function percentage(array &$tickers, string $index1 = 'close', string $index2 = 'open', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->percentage($tickers, $index1, $index2);
        }
        $key = 'percentage('.$index1.','.$index2.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $scale = $this->technical_scale($row[$index1], $row[$index2]);
                $change = $this->technical_relative_change_value($row[$index1], $row[$index2]);
                $row[$key] = bcmul($change ?? '0', '100', $scale);
            }
        }
        unset($row);

        return $key;
    }

    public function difference(array &$tickers, string $index1 = 'close', string $index2 = 'open', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->difference($tickers, $index1, $index2);
        }
        $key = 'difference('.$index1.','.$index2.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = bcsub(bcconv($row[$index1]), bcconv($row[$index2]), $this->technical_scale($row[$index1], $row[$index2]));
            }
        }
        unset($row);

        return $key;
    }

    public function numeric_or(array &$tickers, string $index1 = 'close', string $index2 = 'open', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->numeric_or($tickers, $index1, $index2);
        }
        $key = 'or('.$index1.', '.$index2.')';
        foreach ($tickers as &$row) {
            $row[$key] = (int) $row[$index1] | (int) $row[$index2];
        }
        unset($row);

        return $key;
    }

    public function consecutive(array &$tickers, string $index = 'close', ?int $precision = null, int|float|string|null $condition_value = null, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->consecutive($tickers, $index, $precision, $condition_value);
        }
        $this->require_scale($precision);
        $key = 'consecutive('.$index.')';
        $previous = null;
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                $previous = $row;
                continue;
            }
            $same = $matches = false;
            if ($previous !== null) {
                if (is_numeric($row[$index]) && is_numeric($previous[$index])) {
                    $value = bcconv($row[$index]);
                    $scale = $precision ?? $this->technical_scale($value, $previous[$index], is_numeric($condition_value) ? $condition_value : '0');
                    $same = bccomp(bcconv($previous[$index]), $value, $scale) === 0;
                    $matches = $condition_value === null || (is_numeric($condition_value) && bccomp($value, bcconv($condition_value), $scale) === 0);
                } else {
                    $same = strcasecmp((string) $previous[$index], (string) $row[$index]) === 0;
                    $matches = $condition_value === null || strcasecmp((string) $row[$index], (string) $condition_value) === 0;
                }
            }
            $row[$key] = $same && $matches ? $previous[$key] + 1 : 0;
            $previous = $row;
        }
        unset($row);

        return $key;
    }

    public function delayed(array &$tickers, int $period = 26, string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->delayed($tickers, $period, $index);
        }
        $this->require_period($period);
        $this->ticker_require_history($tickers, $period);
        $key = 'delayed('.$period.','.$index.')';
        $buffer = [];
        $first = null;
        foreach ($tickers as &$row) {
            $first ??= $row[$index];
            $buffer[] = $row[$index];
            $value = count($buffer) > $period ? array_shift($buffer) : $first;
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = $value;
            }
        }
        unset($row);

        return $key;
    }

    public function rename_key(array &$tickers, string $oldkey, string $newkey, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->rename_key($tickers, $oldkey, $newkey);
        }
        if ($oldkey !== $newkey) {
            foreach ($tickers as &$row) {
                $row[$newkey] = $row[$oldkey];
                unset($row[$oldkey]);
            }
            unset($row);
        }

        return $tickers;
    }

    /** Lagged, causal columns for the existing Trademinator convention. */
    public function ichimoku(array &$tickers, int $tenkansen = 9, int $kijunsen = 26, int $chikou = 26, int $senkou_b = 52, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->ichimoku($tickers, $tenkansen, $kijunsen, $chikou, $senkou_b);
        }
        foreach ([$tenkansen, $kijunsen, $chikou, $senkou_b] as $period) {
            $this->require_period($period);
        }
        $this->ticker_require_history($tickers, $kijunsen);
        $tenkan = 'tenkansen('.$tenkansen.')';
        $kijun = 'kijunsen('.$kijunsen.')';
        $chikouKey = 'chikou('.$chikou.')';
        $spanA = 'senkou_a()';
        $spanB = 'senkou_b('.$senkou_b.')';
        [, $high1] = $this->min_max($tickers, $tenkansen, 'high');
        [$low1] = $this->min_max($tickers, $tenkansen, 'low');
        [, $high2] = $this->min_max($tickers, $kijunsen, 'high');
        [$low2] = $this->min_max($tickers, $kijunsen, 'low');
        [, $high3] = $this->min_max($tickers, $senkou_b, 'high');
        [$low3] = $this->min_max($tickers, $senkou_b, 'low');
        $past = $this->delayed($tickers, $chikou, 'close');
        $this->clone_key($tickers, $past, $chikouKey);
        $positions = array_keys($tickers);
        $i = 0;
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $tenkan)) {
                $scale = $this->technical_scale($row[$high1], $row[$low1], $row[$high2], $row[$low2], $row[$high3], $row[$low3]);
                $row[$tenkan] = bcdiv(bcadd($row[$high1], $row[$low1], $scale), '2', $scale);
                $row[$kijun] = bcdiv(bcadd($row[$high2], $row[$low2], $scale), '2', $scale);
                $origin = $i >= $kijunsen ? $tickers[$positions[$i - $kijunsen]] : $row;
                $row[$spanA] = bcdiv(bcadd($origin[$tenkan], $origin[$kijun], $scale), '2', $scale);
                $row[$spanB] = bcdiv(bcadd($origin[$high3], $origin[$low3], $scale), '2', $scale);
            }
            $i++;
        }
        unset($row);

        return [$tenkan, $kijun, $chikouKey, $spanA, $spanB];
    }

    public function sto(array &$tickers, int $period1 = 14, int $period2 = 3, int $period3 = 3, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->sto($tickers, $period1, $period2, $period3);
        }
        foreach ([$period1, $period2, $period3] as $period) {
            $this->require_period($period);
        }
        $fast = '%k_price('.$period1.')';
        $smooth = '%dk_price('.$period1.','.$period2.')';
        $slow = '%d_price('.$period1.','.$period2.','.$period3.')';
        [, $high] = $this->min_max($tickers, $period1, 'high');
        [$low] = $this->min_max($tickers, $period1, 'low');
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $fast)) {
                continue;
            }
            $scale = $this->technical_scale($row['close'], $row[$high], $row[$low]);
            $span = bcsub($row[$high], $row[$low], $scale);
            // Preserve the original price-stochastic flat-window convention.
            $row[$fast] = bccomp($span, '0', $scale) === 0
                ? bcadd('100', '0', $scale)
                : bcmul('100', bcdiv(bcsub($row['close'], $row[$low], $scale), $span, $scale), $scale);
        }
        unset($row);
        $source = $this->sma($tickers, $period2, $fast);
        $this->clone_key($tickers, $source, $smooth);
        $source = $this->sma($tickers, $period3, $smooth);
        $this->clone_key($tickers, $source, $slow);

        return [$fast, $smooth, $slow];
    }

    public function sto_rsi(array &$tickers, int $period1 = 14, int $period2 = 3, int $period3 = 3, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->sto_rsi($tickers, $period1, $period2, $period3);
        }
        foreach ([$period1, $period2, $period3] as $period) {
            $this->require_period($period);
        }
        $this->ticker_require_history($tickers, $period1 - 1);
        $rsiKey = $this->rsi($tickers, $period1);
        $fast = '%k('.$period1.')';
        $smooth = '%dk('.$period1.','.$period2.')';
        $slow = '%d('.$period1.','.$period2.','.$period3.')';
        $buffer = [];
        $count = 0;
        foreach ($tickers as &$row) {
            $count++;
            $position = $row['__ticker_position'] ?? $count;
            if ($position > $period1) {
                $buffer[] = $row[$rsiKey];
                if (count($buffer) > $period1) {
                    array_shift($buffer);
                }
            }
            if ($this->ticker_cached($row, $fast)) {
                continue;
            }
            $scale = $this->technical_scale($row[$rsiKey], ...$buffer);
            if (count($buffer) < $period1) {
                $row[$fast] = bcadd('50', '0', $scale);
                continue;
            }
            $min = bcmin(...$buffer);
            $max = bcmax(...$buffer);
            $span = bcsub($max, $min, $scale);
            $row[$fast] = bccomp($span, '0', $scale) === 0
                ? bcadd('50', '0', $scale)
                : bcmul('100', bcdiv(bcsub($row[$rsiKey], $min, $scale), $span, $scale), $scale);
        }
        unset($row);
        $source = $this->sma($tickers, $period2, $fast);
        $this->clone_key($tickers, $source, $smooth);
        $source = $this->sma($tickers, $period3, $smooth);
        $this->clone_key($tickers, $source, $slow);

        return [$fast, $smooth, $slow];
    }

    public function compare(array &$tickers, string $index = 'close', string $compare = 'open', ?int $digits = null, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->compare($tickers, $index, $compare, $digits);
        }
        $this->require_scale($digits);
        $key = 'compare('.$index.','.$compare.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = bccomp(bcconv($row[$index]), bcconv($row[$compare]), $digits ?? $this->technical_scale($row[$index], $row[$compare]));
            }
        }
        unset($row);

        return $key;
    }

    public function slope(array &$tickers, string $index = 'close', int $offset = 1, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->slope($tickers, $index, $offset);
        }
        $this->require_period($offset);
        $this->ticker_require_history($tickers, $offset);
        $key = 'slope('.$index.','.$offset.')';
        $sign = 'slope_sign('.$index.','.$offset.')';
        $positions = array_keys($tickers);
        $i = 0;
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $previous = $i >= $offset ? $tickers[$positions[$i - $offset]][$index] : $row[$index];
                $scale = $this->technical_scale($row[$index], $previous);
                $row[$key] = bcdiv(bcsub(bcconv($row[$index]), bcconv($previous), $scale), (string) $offset, $scale);
                $row[$sign] = bccomp($row[$key], '0', $scale);
            }
            $i++;
        }
        unset($row);

        return [$key, $sign];
    }

    /** Retrospective annotation: may label the previous row. Not a live signal. */
    public function inflexion(array &$tickers, string $keyA = 'close', string $keyB = 'ema(2,close)', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->inflexion($tickers, $keyA, $keyB);
        }
        $key = 'inflexion('.$keyA.','.$keyB.')';
        if ($tickers === []) {
            return $key;
        }
        $last = end($tickers);
        if (! array_key_exists($keyA, $last) || ! array_key_exists($keyB, $last)) {
            return $key;
        }
        [$fast, $smooth, $slow] = $this->sto($tickers);
        $comparison = $this->compare($tickers, $keyA, $keyB);
        $consecutive = $this->consecutive($tickers, $comparison);
        [, $kijun] = $this->ichimoku($tickers);
        [, $slopeSign] = $this->slope($tickers, $kijun);
        $positions = array_keys($tickers);
        $i = 0;
        foreach ($tickers as &$row) {
            $row[$key] = 0;
            if ($i > 0 && $row[$consecutive] === 0) {
                $previous = $tickers[$positions[$i - 1]];
                $scale = $this->technical_scale($previous[$fast], $previous[$smooth], $previous[$slow]);
                $a = bcdiv(bcadd($previous[$fast], $previous[$smooth], $scale), '2', $scale);
                $b = bcdiv(bcadd($previous[$smooth], $previous[$slow], $scale), '2', $scale);
                $direction = $previous[$comparison];
                if ((bccomp($a, '80', $scale) >= 0 && $direction === 1)
                    || (bccomp($a, '20', $scale) <= 0 && $direction === -1)
                    || (bccomp($b, '70', $scale) >= 0 && $direction === 1)
                    || (bccomp($b, '30', $scale) <= 0 && $direction === -1)) {
                    $tickers[$positions[$i - 1]][$key] = 1;
                }
            }
            $i++;
        }
        unset($row);
        foreach ($tickers as &$row) {
            if ($row[$slopeSign] === 0) {
                $row[$key] = 0;
            }
        }
        unset($row);

        return $key;
    }

    public function md(array &$tickers, int $period, string $key1, string $key2, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->md($tickers, $period, $key1, $key2);
        }
        $this->require_period($period);
        $this->ticker_require_history($tickers, $period - 1);
        $key = "md($period, $key1, $key2)";
        $buffer = [];
        foreach ($tickers as &$row) {
            $buffer[] = bcconv($row[$key1]);
            if (count($buffer) > $period) {
                array_shift($buffer);
            }
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$key2], ...$buffer);
            $sum = '0';
            foreach ($buffer as $value) {
                $sum = bcadd($sum, bcabs(bcsub($value, bcconv($row[$key2]), $scale)), $scale);
            }
            $row[$key] = bcdiv($sum, (string) count($buffer), $scale);
        }
        unset($row);

        return $key;
    }

    public function cci(array &$tickers, int $period = 20, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->cci($tickers, $period);
        }
        $this->require_period($period);
        $tp = $this->tp($tickers);
        $average = $this->sma($tickers, $period, $tp);
        $deviation = $this->md($tickers, $period, $tp, $average);
        $key = 'cci('.$period.')';
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$tp], $row[$average], $row[$deviation]);
            $denominator = bcmul($row[$deviation], '0.015', $scale);
            $row[$key] = bccomp($denominator, '0', $scale) === 0
                ? '0' : bcdiv(bcsub($row[$tp], $row[$average], $scale), $denominator, $scale);
        }
        unset($row);

        return $key;
    }

    /** Preserves the source trait's close-price first-row seed. */
    public function dm(array &$tickers, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->dm($tickers);
        }
        $minus = '-dm()';
        $plus = '+dm()';
        $this->ticker_seed($tickers, [$minus, $plus], 1);
        $previous = null;
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $minus) && $this->ticker_cached($row, $plus)) {
                $previous = $row;
                continue;
            }
            if ($previous === null) {
                $row[$plus] = $row[$minus] = bcconv($row['close']);
            } else {
                $scale = $this->technical_scale($row['high'], $row['low'], $previous['high'], $previous['low']);
                $up = bcsub($row['high'], $previous['high'], $scale);
                $down = bcsub($previous['low'], $row['low'], $scale);
                $row[$plus] = bccomp($up, $down, $scale) > 0 && bccomp($up, '0', $scale) > 0 ? $up : '0';
                $row[$minus] = bccomp($down, $up, $scale) > 0 && bccomp($down, '0', $scale) > 0 ? $down : '0';
            }
            $previous = $row;
        }
        unset($row);

        return [$minus, $plus];
    }

    public function gain(array &$tickers, int $period = 14, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->gain($tickers, $period);
        }
        $this->require_period($period);
        $gainKey = 'average_gain('.$period.')';
        $lossKey = 'average_loss('.$period.')';
        $deltaKey = 'delta('.$period.')';
        $seed = $this->ticker_seed($tickers, [$gainKey, $lossKey], $period + 1);
        $changes = $seed === null ? 0 : $seed['__ticker_position'] - 2;
        $previousClose = null;
        $averageGain = $averageLoss = $gainSum = $lossSum = '0';
        $weight = bcsub((string) $period, '1', 0);
        foreach ($tickers as &$row) {
            $close = bcconv($row['close']);
            if ($seed !== null && $this->ticker_cached($row, $gainKey) && $this->ticker_cached($row, $lossKey)) {
                $changes++;
                $averageGain = (string) $row[$gainKey];
                $averageLoss = (string) $row[$lossKey];
                $previousClose = $close;
                continue;
            }
            $scale = $this->recurrence_scale($close, $previousClose ?? '0', $averageGain, $averageLoss, $gainSum, $lossSum);
            // A previous input price needs guard digits too, independently of state.
            $scale = max($scale, $this->technical_scale($previousClose ?? $close));
            if ($previousClose === null) {
                $row['gain'] = $row['loss'] = $row[$deltaKey] = bcadd('0', '0', $scale);
                $row[$gainKey] = $row[$lossKey] = bcadd('0', '0', $scale);
            } else {
                $delta = bcsub($close, $previousClose, $scale);
                $row[$deltaKey] = $delta;
                $row['gain'] = bccomp($delta, '0', $scale) > 0 ? $delta : bcadd('0', '0', $scale);
                $row['loss'] = bccomp($delta, '0', $scale) < 0 ? bcabs($delta) : bcadd('0', '0', $scale);
                $changes++;
                if ($changes <= $period) {
                    $gainSum = bcadd($gainSum, $row['gain'], $scale);
                    $lossSum = bcadd($lossSum, $row['loss'], $scale);
                    $row[$gainKey] = bcdiv($gainSum, (string) $changes, $scale);
                    $row[$lossKey] = bcdiv($lossSum, (string) $changes, $scale);
                } else {
                    $row[$gainKey] = bcdiv(bcadd(bcmul($averageGain, $weight, $scale), $row['gain'], $scale), (string) $period, $scale);
                    $row[$lossKey] = bcdiv(bcadd(bcmul($averageLoss, $weight, $scale), $row['loss'], $scale), (string) $period, $scale);
                }
            }
            $averageGain = $row[$gainKey];
            $averageLoss = $row[$lossKey];
            $previousClose = $close;
        }
        unset($row);

        return ['gain', 'loss', $lossKey, $gainKey, $deltaKey];
    }

    public function rsi(array &$tickers, int $period = 14, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->rsi($tickers, $period);
        }
        [, , $lossKey, $gainKey] = $this->gain($tickers, $period);
        $key = 'rsi('.$period.')';
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$gainKey], $row[$lossKey]);
            if (bccomp($row[$lossKey], '0', $scale) > 0) {
                $rs = bcdiv($row[$gainKey], $row[$lossKey], $scale);
                $row[$key] = bcsub('100', bcdiv('100', bcadd('1', $rs, $scale), $scale), $scale);
            } elseif (bccomp($row[$gainKey], '0', $scale) === 0) {
                $row[$key] = bcadd('50', '0', $scale);
            } else {
                $row[$key] = bcadd('100', '0', $scale);
            }
        }
        unset($row);

        return $key;
    }

    public function roc(array &$tickers, int $delay = 1, string $index = 'close', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->roc($tickers, $delay, $index);
        }
        $past = $this->delayed($tickers, $delay, $index);
        $key = 'roc('.$delay.','.$index.')';
        $count = 0;
        foreach ($tickers as &$row) {
            $count++;
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$index], $row[$past]);
            $change = ($row['__ticker_position'] ?? $count) <= $delay
                ? null : $this->technical_relative_change_value($row[$index], $row[$past]);
            $row[$key] = bcmul($change ?? '0', '100', $scale);
        }
        unset($row);

        return $key;
    }

    public function macd(array &$tickers, int $short_period = 12, int $long_period = 26, int $signal_period = 9, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->macd($tickers, $short_period, $long_period, $signal_period);
        }
        return $this->moving_average_oscillator($tickers, $short_period, $long_period, $signal_period, 'macd');
    }

    public function ppo(array &$tickers, int $short_period = 12, int $long_period = 26, int $signal_period = 9, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->ppo($tickers, $short_period, $long_period, $signal_period);
        }
        return $this->moving_average_oscillator($tickers, $short_period, $long_period, $signal_period, 'ppo');
    }

    private function moving_average_oscillator(array &$tickers, int $short, int $long, int $signal, string $kind): array
    {
        foreach ([$short, $long, $signal] as $period) {
            $this->require_period($period);
        }
        $key = $kind.'('.$short.','.$long.','.$signal.')';
        $signalKey = $kind.'_signal('.$key.')';
        $deltaKey = 'delta_'.$kind.'('.$key.','.$signalKey.')';
        $shortKey = $this->ema($tickers, $short, 'close');
        $longKey = $this->ema($tickers, $long, 'close');
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$shortKey], $row[$longKey]);
            $difference = bcsub($row[$shortKey], $row[$longKey], $scale);
            $row[$key] = $kind === 'macd' ? $difference
                : (bccomp($row[$longKey], '0', $scale) === 0 ? bcadd('0', '0', $scale)
                    : bcmul(bcdiv($difference, $row[$longKey], $scale), '100', $scale));
        }
        unset($row);
        $source = $this->ema($tickers, $signal, $key);
        $this->clone_key($tickers, $source, $signalKey);
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $deltaKey)) {
                $scale = $this->technical_scale($row[$key], $row[$signalKey]);
                $row[$deltaKey] = bcsub($row[$key], $row[$signalKey], $scale);
            }
        }
        unset($row);

        return [$key, $signalKey, $deltaKey];
    }

    public function atr(array &$tickers, int $period = 14, string $average_function = 'smma', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->atr($tickers, $period, $average_function);
        }
        $this->require_period($period);
        $average_function = strtolower($average_function);
        if (! in_array($average_function, ['sma', 'ema', 'smma'], true)) {
            throw new InvalidArgumentException('ATR averaging must be sma, ema or smma.');
        }
        $key = 'atr('.$period.($average_function === 'smma' ? '' : ','.$average_function).')';
        $tr = $this->tr($tickers);
        $source = $this->{$average_function}($tickers, $period, $tr);
        $this->clone_key($tickers, $source, $key);

        return $key;
    }

    public function atrp(array &$tickers, int $period = 14, string $average_function = 'smma', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->atrp($tickers, $period, $average_function);
        }
        $average_function = strtolower($average_function);
        $atr = $this->atr($tickers, $period, $average_function);
        $key = 'atrp('.$period.($average_function === 'smma' ? '' : ','.$average_function).')';
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $close = bcconv($row['close']);
            $scale = $this->technical_scale($row[$atr], $close);
            // A zero close is not replaced by a fictitious exchange tick size.
            $row[$key] = bccomp($close, '0', $scale) === 0 ? bcadd('0', '0', $scale)
                : bcmul(bcdiv($row[$atr], $close, $scale), '100', $scale);
        }
        unset($row);

        return $key;
    }

    /** Legacy SMA directional ratios followed by a Wilder-smoothed DX. */
    public function adx(array &$tickers, int $period = 14, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->adx($tickers, $period);
        }
        $this->require_period($period);
        $adx = 'adx('.$period.')';
        $dx = 'dx()';
        $plus = '+di('.$period.')';
        $minus = '-di('.$period.')';
        $tr = $this->tr($tickers);
        [$minusDm, $plusDm] = $this->dm($tickers);
        $plusAverage = $this->sma($tickers, $period, $plusDm);
        $minusAverage = $this->sma($tickers, $period, $minusDm);
        $trAverage = $this->sma($tickers, $period, $tr);
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $dx) && $this->ticker_cached($row, $plus) && $this->ticker_cached($row, $minus)) {
                continue;
            }
            $scale = $this->technical_scale($row[$plusAverage], $row[$minusAverage], $row[$trAverage]);
            if (bccomp($row[$trAverage], '0', $scale) === 0) {
                $row[$plus] = $row[$minus] = $row[$dx] = bcadd('0', '0', $scale);
                continue;
            }
            $row[$plus] = bcmul('100', bcabs(bcdiv($row[$plusAverage], $row[$trAverage], $scale)), $scale);
            $row[$minus] = bcmul('100', bcabs(bcdiv($row[$minusAverage], $row[$trAverage], $scale)), $scale);
            $sub = bcsub($row[$plus], $row[$minus], $scale);
            $add = bcadd($row[$plus], $row[$minus], $scale);
            $row[$dx] = bccomp($add, '0', $scale) === 0 ? bcadd('0', '0', $scale)
                : bcmul('100', bcabs(bcdiv($sub, $add, $scale)), $scale);
        }
        unset($row);
        $source = $this->smma($tickers, $period, $dx);
        $this->clone_key($tickers, $source, $adx);

        return [$adx, $dx, $minus, $plus];
    }

    public function midkey(array &$tickers, string $key1 = 'high', string $key2 = 'low', ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->midkey($tickers, $key1, $key2);
        }
        $key = 'average('.$key1.','.$key2.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $scale = $this->technical_scale($row[$key1], $row[$key2]);
                $row[$key] = bcdiv(bcadd(bcconv($row[$key1]), bcconv($row[$key2]), $scale), '2', $scale);
            }
        }
        unset($row);

        return $key;
    }

    public function ao(array &$tickers, int $short = 5, int $long = 34, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->ao($tickers, $short, $long);
        }
        $this->require_period($short);
        $this->require_period($long);
        $key = 'ao('.$short.','.$long.')';
        $color = 'ao_color('.$short.','.$long.')';
        $mid = $this->midkey($tickers);
        $shortKey = $this->sma($tickers, $short, $mid);
        $longKey = $this->sma($tickers, $long, $mid);
        $previous = null;
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $scale = $this->technical_scale($row[$shortKey], $row[$longKey], $previous ?? '0');
                $row[$key] = bcsub($row[$shortKey], $row[$longKey], $scale);
                $row[$color] = $previous === null || bccomp($row[$key], $previous, $scale) >= 0 ? 'green' : 'red';
            }
            $previous = $row[$key];
        }
        unset($row);

        return [$key, $color, $mid];
    }

    public function ac(array &$tickers, int $period = 5, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->ac($tickers, $period);
        }
        [$ao] = $this->ao($tickers, $period, 34);
        $average = $this->sma($tickers, $period, $ao);
        $key = 'ac('.$period.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $row[$key] = bcsub($row[$ao], $row[$average], $this->technical_scale($row[$ao], $row[$average]));
            }
        }
        unset($row);

        return $key;
    }

    public function bb(array &$tickers, int $period = 20, int|float|string $stddev = 2, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->bb($tickers, $period, $stddev);
        }
        $this->require_period($period);
        $this->ticker_require_history($tickers, $period - 1);
        $stddev = bcconv($stddev);
        if (bccomp($stddev, '0', bcdec([$stddev])) < 0) {
            throw new InvalidArgumentException('Bollinger multiplier cannot be negative.');
        }
        $high = 'bb_high('.$period.','.$stddev.')';
        $low = 'bb_low('.$period.','.$stddev.')';
        $width = 'bb_bw('.$period.','.$stddev.')';
        $stdKey = 'stddev('.$period.')';
        $average = $this->sma($tickers, $period, 'close');
        $buffer = [];
        foreach ($tickers as &$row) {
            $buffer[] = $row['close'];
            if (count($buffer) > $period) {
                array_shift($buffer);
            }
            if ($this->ticker_cached($row, $high)) {
                continue;
            }
            $scale = $this->technical_scale($stddev, $row[$average], ...$buffer);
            // Variance squares deltas. Keep twice the resolved fractional scale
            // internally, then publish at the caller's calculation scale.
            if ($scale > intdiv(PHP_INT_MAX, 2)) {
                throw new InvalidArgumentException('Bollinger calculation scale is too large.');
            }
            $std = count($buffer) === 1 ? '0' : bcstddev($buffer, false, $scale * 2);
            $row[$stdKey] = bcadd($std, '0', $scale);
            $distance = bcmul($stddev, $std, $scale);
            $row[$high] = bcadd($row[$average], $distance, $scale);
            $row[$low] = bcsub($row[$average], $distance, $scale);
            $row[$width] = bccomp($row[$average], '0', $scale) === 0 ? bcadd('0', '0', $scale)
                : bcdiv(bcsub($row[$high], $row[$low], $scale), $row[$average], $scale);
        }
        unset($row);

        return [$low, $high, $width, $stdKey, $average];
    }

    public function keltner(array &$tickers, int $period = 20, int|float|string $bandwidth = '1.5', ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->keltner($tickers, $period, $bandwidth);
        }
        $this->require_period($period);
        $bandwidth = bcconv($bandwidth);
        if (bccomp($bandwidth, '0', bcdec([$bandwidth])) < 0) {
            throw new InvalidArgumentException('Keltner bandwidth cannot be negative.');
        }
        $high = 'keltner_high('.$period.','.$bandwidth.')';
        $low = 'keltner_low('.$period.','.$bandwidth.')';
        $typical = $this->tp($tickers);
        $average = $this->sma($tickers, $period, $typical);
        $atr = $this->atr($tickers, 10);
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $high)) {
                $scale = $this->technical_scale($row[$average], $row[$atr], $bandwidth);
                $distance = bcmul($bandwidth, $row[$atr], $scale);
                $row[$high] = bcadd($row[$average], $distance, $scale);
                $row[$low] = bcsub($row[$average], $distance, $scale);
            }
        }
        unset($row);

        return [$low, $high, $average];
    }

    public function squeeze(array &$tickers, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->squeeze($tickers);
        }
        $key = 'squeeze()';
        $choppy = 'choppy()';
        [, $bbHigh] = $this->bb($tickers, 20, 2);
        [, $keltnerHigh] = $this->keltner($tickers, 20, '1.5');
        $count = 0;
        foreach ($tickers as &$row) {
            $count++;
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row[$bbHigh], $row[$keltnerHigh]);
            if (($row['__ticker_position'] ?? $count) <= 2) {
                $row[$key] = bcadd('0', '0', $scale);
                $row[$choppy] = 0;
            } else {
                $row[$key] = bcsub($row[$bbHigh], $row[$keltnerHigh], $scale);
                $row[$choppy] = bccomp($row[$key], '0', $scale) > 0 ? 0 : 1;
            }
        }
        unset($row);

        return [$key, $choppy];
    }

    public function super_trend(array &$tickers, int $period = 10, int|float|string $factor = 3, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->super_trend($tickers, $period, $factor);
        }
        $this->require_period($period);
        $factor = bcconv($factor);
        if (bccomp($factor, '0', bcdec([$factor])) < 0) {
            throw new InvalidArgumentException('Supertrend factor cannot be negative.');
        }
        $key = 'super_trend('.$period.','.$factor.')';
        $upper = 'super_trend_upper('.$period.','.$factor.')';
        $lower = 'super_trend_lower('.$period.','.$factor.')';
        $this->ticker_seed($tickers, [$key, $upper, $lower], $period);
        $mid = $this->midkey($tickers);
        $atr = $this->atr($tickers, $period);
        $previous = null;
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $key)) {
                $previous = $row;
                continue;
            }
            $scale = $this->technical_scale($row[$mid], $row[$atr], $row['close'], $factor,
                $previous[$upper] ?? '0', $previous[$lower] ?? '0');
            $distance = bcmul($factor, $row[$atr], $scale);
            $row[$upper] = bcadd($row[$mid], $distance, $scale);
            $row[$lower] = bcsub($row[$mid], $distance, $scale);
            $direction = -1;
            if ($previous !== null) {
                if (! (bccomp($row[$lower], $previous[$lower], $scale) > 0 || bccomp($previous['close'], $previous[$lower], $scale) < 0)) {
                    $row[$lower] = $previous[$lower];
                }
                if (! (bccomp($row[$upper], $previous[$upper], $scale) < 0 || bccomp($previous['close'], $previous[$upper], $scale) > 0)) {
                    $row[$upper] = $previous[$upper];
                }
                $direction = bccomp($previous[$key], $previous[$upper], $scale) === 0
                    ? (bccomp($row['close'], $row[$upper], $scale) > 0 ? 1 : -1)
                    : (bccomp($row['close'], $row[$lower], $scale) < 0 ? -1 : 1);
            }
            $row[$key] = $direction === 1 ? $row[$lower] : $row[$upper];
            $previous = $row;
        }
        unset($row);

        return [$key, $upper, $lower];
    }

    public function price_speed(array &$tickers, int $period = 1, int|float|string|null $bar_time_in_seconds = null, ?PrecisionContext $context = null): ?string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->price_speed($tickers, $period, $bar_time_in_seconds);
        }
        $this->require_period($period);
        if ($tickers === [] || (count($tickers) < 2 && $bar_time_in_seconds === null)) {
            return null;
        }
        if ($bar_time_in_seconds === null) {
            $last = end($tickers);
            $previous = prev($tickers);
            $a = $last['microtimestamp'] ?? $last[0] ?? null;
            $b = $previous['microtimestamp'] ?? $previous[0] ?? null;
            if (! is_numeric($a) || ! is_numeric($b)) {
                return null;
            }
            $bar_time_in_seconds = bcdiv(bcabs(bcsub(bcconv($a), bcconv($b), 0)), '1000', 3);
            $bar_time_in_seconds = rtrim(rtrim($bar_time_in_seconds, '0'), '.') ?: '0';
        }
        $seconds = bcconv($bar_time_in_seconds);
        if (bccomp($seconds, '0', bcdec([$seconds])) <= 0) {
            return null;
        }
        $key = 'speed('.$period.','.$seconds.')';
        $past = $this->delayed($tickers, $period, 'close');
        $count = 0;
        foreach ($tickers as &$row) {
            $count++;
            if ($this->ticker_cached($row, $key)) {
                continue;
            }
            $scale = $this->technical_scale($row['close'], $row[$past], $seconds);
            $bars = min($period, $row['__ticker_position'] ?? $count);
            $time = bcmul((string) $bars, $seconds, $scale);
            $row[$key] = bccomp($time, '0', $scale) === 0 ? bcadd('0', '0', $scale)
                : bcdiv(bcsub($row['close'], $row[$past], $scale), $time, $scale);
        }
        unset($row);

        return $key;
    }

    public function volume_activity(array &$tickers, int $period = 20, ?PrecisionContext $context = null): string
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->volume_activity($tickers, $period);
        }
        $average = $this->sma($tickers, $period, 'volume');
        $key = 'volume_activity('.$period.')';
        foreach ($tickers as &$row) {
            if (! $this->ticker_cached($row, $key)) {
                $scale = $this->technical_scale($row['volume'], $row[$average]);
                $row[$key] = $this->technical_relative_change_value($row['volume'], $row[$average]) ?? bcadd('0', '0', $scale);
            }
        }
        unset($row);

        return $key;
    }

    public function candle_geometry(array &$tickers, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->candle_geometry($tickers);
        }
        $direction = $this->compare($tickers, 'close', 'open');
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, 'candle.body')) {
                continue;
            }
            foreach ($this->technical_candle_geometry($row['open'], $row['high'], $row['low'], $row['close']) as $name => $value) {
                $row['candle.'.$name] = $value;
            }
            $row['candle.direction'] = $row[$direction];
        }
        unset($row);

        return ['candle.body', 'candle.upper_wick', 'candle.lower_wick', 'candle.direction'];
    }
}
