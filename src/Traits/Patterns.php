<?php

declare(strict_types=1);

namespace Trademinator\Indicators\Traits;

use LogicException;
use Trademinator\BcMath\PrecisionContext;

use function Trademinator\BcMath\bcabs;

/** Existing pattern definitions; unfinished routines remain explicitly quarantined. */
trait Patterns
{
    use Technical;

    public function candle_anatomy(array &$tickers, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->candle_anatomy($tickers);
        }
        $keys = ['is_black()', 'is_long_black()', 'is_short_black()', 'is_black_marubozu()',
            'is_white()', 'is_long_white()', 'is_short_white()', 'is_white_marubozu()', 'is_doji()', 'is_super_doji()'];
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $keys[0])) {
                continue;
            }
            $scale = $this->technical_scale($row['open'], $row['high'], $row['low'], $row['close'], '0.001');
            $direction = bccomp($row['close'], $row['open'], $scale);
            $body = bcabs(bcsub($row['close'], $row['open'], $scale));
            $range = bcsub($row['high'], $row['low'], $scale);
            // Preserve the source definitions, including the 0.001 denominator
            // stabilizer. Compare products instead of dividing/rounding ratios.
            $denominator = bcadd('0.001', $range, $scale);
            $long = bccomp($body, bcmul('0.6', $denominator, $scale), $scale) > 0;
            $short = bccomp($range, bcmul('3', $body, $scale), $scale) > 0;
            $row[$keys[0]] = (int) ($direction < 0);
            $row[$keys[1]] = (int) ($direction < 0 && $long);
            $row[$keys[2]] = (int) ($direction < 0 && $short);
            $row[$keys[3]] = (int) ($direction < 0 && bccomp($row['high'], $row['open'], $scale) === 0 && bccomp($row['close'], $row['low'], $scale) === 0);
            $row[$keys[4]] = (int) ($direction > 0);
            $row[$keys[5]] = (int) ($direction > 0 && $long);
            $row[$keys[6]] = (int) ($direction > 0 && $short);
            $row[$keys[7]] = (int) ($direction > 0 && bccomp($row['high'], $row['close'], $scale) === 0 && bccomp($row['open'], $row['low'], $scale) === 0);
            $row[$keys[8]] = (int) ($direction === 0);
            $row[$keys[9]] = (int) ($direction === 0 && bccomp($row['high'], $row['low'], $scale) === 0);
        }
        unset($row);

        return $keys;
    }

    public function is_dragonfly(array &$tickers): never
    {
        throw new LogicException('Dragonfly pattern detection is unfinished and intentionally quarantined.');
    }

    public function is_grave_stone(array &$tickers): never
    {
        throw new LogicException('Gravestone pattern detection is unfinished and intentionally quarantined.');
    }

    public function is_inverted_hammer_or_shooting_star(array &$tickers, ?PrecisionContext $context = null): array
    {
        if ($context !== null) {
            return $this->withPrecisionContext($context)->is_inverted_hammer_or_shooting_star($tickers);
        }
        $hammer = 'is_inverted_hammer()';
        $star = 'is_shooting_star()';
        foreach ($tickers as &$row) {
            if ($this->ticker_cached($row, $hammer)) {
                continue;
            }
            $scale = $this->technical_scale($row['open'], $row['high'], $row['low'], $row['close'], '0.001');
            $range = bcsub($row['high'], $row['low'], $scale);
            $signedBody = bcsub($row['open'], $row['close'], $scale);
            $denominator = bcadd('0.001', $range, $scale);
            $highClose = bcsub($row['high'], $row['close'], $scale);
            $highOpen = bcsub($row['high'], $row['open'], $scale);
            $sixty = bcmul('0.6', $denominator, $scale);
            $seventyFive = bcmul('0.75', $denominator, $scale);
            $row[$hammer] = (int) (bccomp($range, bcmul('3', $signedBody, $scale), $scale) > 0
                && bccomp($highClose, $sixty, $scale) > 0 && bccomp($highOpen, $sixty, $scale) > 0);
            $row[$star] = (int) (bccomp($range, bcmul('4', $signedBody, $scale), $scale) > 0
                && bccomp($highClose, $seventyFive, $scale) >= 0 && bccomp($highOpen, $seventyFive, $scale) >= 0);
        }
        unset($row);

        return [$hammer, $star];
    }
}
