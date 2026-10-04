<?php

declare(strict_types=1);

namespace Trademinator\Indicators;

/** Function API for the CCXT ingestion boundary; identical to the trait method. */
function normalize_ticker(array &$tickers, bool $reindex = false, string $indexUnit = 'seconds'): array
{
    return (new Indicators())->normalize_ticker($tickers, $reindex, $indexUnit);
}
