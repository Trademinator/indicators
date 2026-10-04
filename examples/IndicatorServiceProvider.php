<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Indicators;

/** Copy into the Laravel application; this is not an auto-registered provider. */
final class IndicatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Indicators::class, static function ($app): Indicators {
            // Application configuration lives outside the reusable library.
            $settings = $app['config']->get('indicators', []);

            return new Indicators(policy: new PrecisionPolicy(
                minimumScale: (int) ($settings['minimum_scale'] ?? 16),
                guardDigits: (int) ($settings['guard_digits'] ?? 4),
                maximumScale: (int) ($settings['maximum_scale'] ?? 32),
                trimTrailingZeros: (bool) ($settings['trim_trailing_zeros'] ?? true),
            ));
        });
    }
}
