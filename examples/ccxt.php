<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Indicators\Indicators;

use function Trademinator\Indicators\normalize_ticker;

if (! class_exists(\ccxt\kraken::class)) {
    throw new RuntimeException('Install optional ccxt/ccxt in the application running this example.');
}

$exchange = new \ccxt\kraken(['enableRateLimit' => true]);
$calculator = new Indicators();

// Repeat these TWO operations for every pagination/backfill/refresh request.
$tickers = $exchange->fetch_ohlcv('BTC/USD', '1m', null, 100);
normalize_ticker($tickers, reindex: true, indexUnit: 'milliseconds');

// Apply application-owned closed-candle, continuity and deduplication rules
// before training or persisting derived data. This example only calculates.
$key = $calculator->ema($tickers, 14);
foreach ($tickers as $timestamp => $row) {
    echo $timestamp.' '.$row[$key].PHP_EOL;
}
