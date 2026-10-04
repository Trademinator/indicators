<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Indicators;

use function Trademinator\Indicators\normalize_ticker;

// Synthetic CCXT-shaped data, not live market prices.
$tickers = [
    [1700000000000, '1.00000001', '1.00000003', '1.00000000', '1.00000002', '100'],
    [1700000060000, '1.00000002', '1.00000005', '1.00000001', '1.00000004', '120'],
    [1700000120000, '1.00000004', '1.00000006', '1.00000002', '1.00000003', '110'],
    [1700000180000, '1.00000003', '1.00000007', '1.00000002', '1.00000006', '150'],
];
normalize_ticker($tickers, reindex: true, indexUnit: 'milliseconds');

$policy = new PrecisionPolicy(minimumScale: 16, guardDigits: 4, maximumScale: 48);
$context = new PrecisionContext(scale: 24, policy: $policy);
$indicators = new Indicators(precision: $context);

$ema = $indicators->ema($tickers, 3);
$rsi = $indicators->rsi($tickers, 3);
$atrp = $indicators->atrp($tickers, 3);
$indicators->candle_anatomy($tickers);

$last = end($tickers);
echo json_encode([
    'timestampMilliseconds' => $last['microtimestamp'],
    'close' => $last['close'],
    $ema => $last[$ema],
    $rsi => $last[$rsi],
    $atrp => $last[$atrp],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
