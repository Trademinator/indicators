<?php

declare(strict_types=1);

namespace Trademinator\Indicators;

use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Traits\Patterns;

/** Optional facade for plain PHP and dependency-injected applications alike. */
final class Indicators
{
    use Patterns;

    public function __construct(?PrecisionContext $precision = null, ?PrecisionPolicy $policy = null)
    {
        if ($policy !== null) {
            $this->setPrecisionPolicy($policy);
        }
        if ($precision !== null) {
            $this->setPrecisionContext($precision);
        }
    }
}
