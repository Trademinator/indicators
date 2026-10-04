<?php

declare(strict_types=1);

namespace Trademinator\Indicators\Traits;

use InvalidArgumentException;
use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;

use function Trademinator\BcMath\bcdec;

/** Per-calculator precision. No environment, framework or process-global state. */
trait PrecisionAware
{
    private ?PrecisionContext $indicatorPrecision = null;
    private ?PrecisionPolicy $indicatorPolicy = null;

    /** Configure a trait consumer without imposing a constructor on its class. */
    public function setPrecisionContext(?PrecisionContext $precision): static
    {
        $this->indicatorPrecision = $precision;
        if ($precision !== null) {
            $this->indicatorPolicy = $precision->policy;
        }

        return $this;
    }

    /** Changing the policy clears a previously resolved context. */
    public function setPrecisionPolicy(PrecisionPolicy $policy): static
    {
        $this->indicatorPolicy = $policy;
        $this->indicatorPrecision = null;

        return $this;
    }

    /** Clone configuration, leaving the original calculator untouched. */
    public function withPrecisionContext(PrecisionContext $precision): static
    {
        $copy = clone $this;

        return $copy->setPrecisionContext($precision);
    }

    public function precisionPolicy(): PrecisionPolicy
    {
        return $this->indicatorPolicy ??= new PrecisionPolicy();
    }

    /** Resolve once, then pass the context to the calculator or reuse a clone. */
    public function precisionContext(array $values): PrecisionContext
    {
        return PrecisionContext::fromValues($values, $this->precisionPolicy());
    }

    /** An explicit context is an O(1) scale lookup; the fallback adapts to inputs. */
    public function technical_scale(int|float|string ...$values): int
    {
        return $this->indicatorPrecision?->scale
            ?? PrecisionContext::fromValues($values, $this->precisionPolicy())->scale;
    }

    /** Keep recurrence digits without adding guard digits again at every step. */
    private function recurrence_scale(int|float|string $value, int|float|string ...$state): int
    {
        if ($this->indicatorPrecision !== null) {
            return $this->indicatorPrecision->scale;
        }

        return max(
            $this->technical_scale($value),
            min($this->precisionPolicy()->maximumScale, bcdec($state, true)),
        );
    }

    /** Exact sums may retain input digits; only published results use policy scale. */
    private function accumulator_scale(int $previousScale, int|float|string $value): int
    {
        return max($previousScale, bcdec([$value]));
    }

    private function require_period(int $period): void
    {
        if ($period < 1) {
            throw new InvalidArgumentException('Indicator period must be greater than zero.');
        }
    }

    private function require_scale(?int $scale): void
    {
        if ($scale !== null && ($scale < 0 || $scale > $this->precisionPolicy()->maximumScale)) {
            throw new InvalidArgumentException('Scale must be non-negative and within the precision policy maximumScale.');
        }
    }
}
