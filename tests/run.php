<?php

declare(strict_types=1);

use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Indicators;

use function Trademinator\BcMath\bcabs;
use function Trademinator\BcMath\bcconv;
use function Trademinator\Indicators\normalize_ticker;

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require $autoload;
// The development-only override permits an external arithmetic oracle. No
// oracle/polyfill is distributed; normal Composer/CI tests require native BCMath.
if (! extension_loaded('bcmath') && ! defined('INDICATORS_REFERENCE_BACKEND')) {
    fwrite(STDERR, "The native PHP BCMath extension is required.\n");
    exit(1);
}
echo extension_loaded('bcmath') ? "Backend: native PHP BCMath\n" : "Backend: external decimal reference (NOT native BCMath validation)\n";

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$tests = [];
function test(string $name, Closure $run): void { global $tests; $tests[$name] = $run; }
function eq(mixed $actual, mixed $expected, string $message = ''): void {
    if ($actual !== $expected) {
        throw new RuntimeException(($message !== '' ? $message.': ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}
function yes(bool $condition, string $message = 'Condition failed'): void { if (! $condition) throw new RuntimeException($message); }
function decimal(string|int $actual, string $expected, int $scale = 12): void {
    eq(bccomp((string) $actual, $expected, $scale), 0, 'Decimal mismatch: '.$actual.' vs '.$expected);
}
function near(string $actual, string $expected, string $tolerance = '0.000000000001'): void {
    yes(bccomp(bcabs(bcsub($actual, $expected, 64)), $tolerance, 64) <= 0, 'Not close: '.$actual.' vs '.$expected);
}
function raises(string $class, Closure $run): void {
    try { $run(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException('Expected '.$class);
}
function candles(array $prices): array {
    $rows = [];
    foreach ($prices as $i => $price) {
        $rows[] = [1700000000000 + $i * 60000, $price, $price, $price, $price, '10'];
    }
    normalize_ticker($rows);
    return $rows;
}
function fixture(int $count = 120, bool $tiny = false): array {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $open = (string) (100 + (($i * 7) % 29));
        $close = (string) (100 + (($i * 11) % 29));
        $high = (string) (max((int) $open, (int) $close) + 3);
        $low = (string) (min((int) $open, (int) $close) - 3);
        if ($tiny) {
            $open = bcdiv($open, '100000000000000', 20);
            $close = bcdiv($close, '100000000000000', 20);
            $high = bcdiv($high, '100000000000000', 20);
            $low = bcdiv($low, '100000000000000', 20);
        }
        $rows[] = [1700000000000 + $i * 60000, $open, $high, $low, $close, (string) (10 + ($i % 13))];
    }
    normalize_ticker($rows, true, 'milliseconds');
    return $rows;
}
function calls(): array {
    return [
        'normalize'=>[], 'ema'=>[3], 'sma'=>[3], 'smma'=>[3], 'tp'=>[], 'tr'=>[], 'min_max'=>[3],
        'chop'=>[3], 'percentage'=>[], 'difference'=>[], 'numeric_or'=>[], 'consecutive'=>[],
        'delayed'=>[3], 'ichimoku'=>[3,5,5,8], 'sto'=>[3,2,2], 'sto_rsi'=>[3,2,2],
        'compare'=>[], 'slope'=>[], 'inflexion'=>[], 'md'=>[3,'close','open'], 'cci'=>[3],
        'dm'=>[], 'gain'=>[3], 'rsi'=>[3], 'roc'=>[3], 'macd'=>[3,5,2], 'ppo'=>[3,5,2],
        'atr'=>[3], 'atrp'=>[3], 'adx'=>[3], 'midkey'=>[], 'ao'=>[3,5], 'ac'=>[3],
        'bb'=>[3], 'keltner'=>[3], 'squeeze'=>[], 'super_trend'=>[3], 'price_speed'=>[1,'60'],
        'volume_activity'=>[3], 'candle_geometry'=>[], 'candle_anatomy'=>[], 'is_inverted_hammer_or_shooting_star'=>[],
    ];
}
function pipeline(Indicators $i, array &$rows): array {
    $keys = [];
    foreach (['ema'=>[7], 'sma'=>[7], 'smma'=>[7], 'tp'=>[], 'tr'=>[], 'atr'=>[7], 'atrp'=>[7],
        'rsi'=>[7], 'sto_rsi'=>[7,3,3], 'cci'=>[7], 'roc'=>[3], 'slope'=>['close',2],
        'volume_activity'=>[7], 'candle_geometry'=>[], 'candle_anatomy'=>[]] as $method=>$args) {
        $result = $i->{$method}($rows, ...$args);
        array_push($keys, ...(array) $result);
    }
    return array_values(array_unique($keys));
}

test('composer metadata and framework-independent requirements', function () {
    $json = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    eq($json['name'], 'trademinator/indicators');
    eq($json['require']['trademinator/bcmath'], '^0.1.0');
    yes(! isset($json['require']['laravel/framework']));
    yes(! isset($json['require']['ccxt/ccxt']));
    yes(! class_exists('Illuminate\\Foundation\\Application'));
});
test('all migrated indicator entry points exist', function () {
    $i = new Indicators();
    foreach (array_keys(calls()) as $name) yes(method_exists($i, $name), $name);
    foreach (['technical_scale','technical_true_range_value','technical_typical_price_value',
        'technical_relative_change_value','technical_normalized_difference_value','technical_candle_geometry',
        'rename_key','normalize_ticker','ticker_slice','ticker_slide','clone_key','delete_key',
        'is_dragonfly','is_grave_stone'] as $name) yes(method_exists($i, $name), $name);
});
test('source has no framework/global-precision coupling', function () {
    foreach (glob(dirname(__DIR__).'/src/Traits/*.php') as $file) {
        $source = file_get_contents($file);
        foreach (['EXCHANGE_ROUND_DECIMALS','bcscale(','global $debug','Illuminate\\','$this->bcconv(', '$this->bcdec(', 'stats_standard_deviation('] as $forbidden) {
            yes(! str_contains($source, $forbidden), basename($file).': '.$forbidden);
        }
    }
    yes(! defined('EXCHANGE_ROUND_DECIMALS'));
});
test('trait consumers compose without constructor/framework requirements', function () {
    $i = new class {
        use Trademinator\Indicators\Traits\Technical;
        use Trademinator\Indicators\Traits\Patterns;
        use Trademinator\Indicators\Traits\TickerManipulation;
    };
    $rows = candles(['1','2']);
    eq($i->sma($rows, 2), 'sma(2,close)');
    decimal($rows[1]['sma(2,close)'], '1.5');
});
test('default adaptive precision and trailing zeros', function () {
    $i = new Indicators();
    eq($i->technical_scale(), 16);
    eq($i->technical_scale('1.0000000000000000000000000000000000'), 16);
    eq($i->technical_scale('1e-20'), 24);
    eq($i->technical_scale('1e-40'), 32);
});
test('custom policy supports deeper fractional prices', function () {
    $i = new Indicators(policy: new PrecisionPolicy(20, 6, 80));
    eq($i->technical_scale('1e-40'), 46);
    decimal($i->technical_true_range_value('2e-40', '1e-40'), '0.'.str_repeat('0',39).'1', 46);
});
test('explicit contexts are reusable and object-local', function () {
    $policy = new PrecisionPolicy(2, 0, 8);
    $context = new PrecisionContext(2, $policy);
    $i = new Indicators($context);
    eq($i->technical_scale('1e-20'), 2);
    eq((new Indicators())->technical_scale('1e-20'), 24);
    eq($i->precisionContext(['1.25'])->scale, 2);
});
test('per-call context does not modify the original calculator', function () {
    $i = new Indicators();
    $context = new PrecisionContext(3, new PrecisionPolicy(0, 0, 8));
    $rows = candles(['1','2']);
    $key = $i->sma($rows, 2, context: $context);
    eq($rows[1][$key], '1.500');
    eq($i->technical_scale(), 16);
    eq($i->technical_typical_price_value('1','2','3', context: $context), '2.000');
});
test('normalization converts scientific floats and strings', function () {
    $rows = [[1700000000123,1e-8,'2e-8','5e-9','1.5e-8',2]];
    $result = normalize_ticker($rows);
    eq($result, $rows);
    eq($rows[0]['open'], '0.000000010');
    eq($rows[0]['close'], '0.000000015');
    eq($rows[0]['microtimestamp'], 1700000000123);
    eq($rows[0]['human_date'], '20231114221320');
    yes(! isset($rows[0][0]));
});
test('normalization is idempotent and preserves named metadata', function () {
    $rows = [[1700000000000,'1','2','0','1.5','3','tag'=>'sample']];
    normalize_ticker($rows); $before = $rows; normalize_ticker($rows);
    eq($rows, $before); eq($rows[0]['tag'], 'sample');
});
test('normalization preserves arbitrary input keys', function () {
    $rows = ['first'=>[1700000000000,'1','1','1','1','0']];
    normalize_ticker($rows); eq(array_keys($rows), ['first']);
});
test('normalization commits atomically', function () {
    $rows = [[1700000000000,'1','2','0','1','0'], [1700000060000,'5','2','0','1','0']];
    $before = $rows;
    raises(InvalidArgumentException::class, function () use (&$rows) { normalize_ticker($rows); });
    eq($rows, $before);
});
test('normalization validates exact high-low relationships', function () {
    $rows = [[1700000000000,'0.0000000000000000000000000000000000000001',
        '0.0000000000000000000000000000000000000001', '0',
        '0.0000000000000000000000000000000000000002','0']];
    raises(InvalidArgumentException::class, function () use (&$rows) { normalize_ticker($rows); });
});
foreach (['bad timestamp'=>[1.5,'1','2','0','1','1'], 'negative volume'=>[1,'1','2','0','1','-1'],
    'nan'=>[1,NAN,'2','0','1','1'], 'infinity'=>[1,'1',INF,'0','1','1'],
    'malformed decimal'=>[1,'nope','2','0','1','1'], 'missing close'=>[1,'1','2','0']] as $name=>$row) {
    test('normalization rejects '.$name, function () use ($row) { $rows=[$row]; raises(InvalidArgumentException::class, function () use (&$rows) { normalize_ticker($rows); }); });
}
test('missing volume defaults to zero', function () {
    $rows = [[1700000000000,'1','2','0','1']]; normalize_ticker($rows); eq($rows[0]['volume'],'0');
});
test('seconds collision is rejected; millisecond indexing preserves both', function () {
    $rows = [[1700000000001,'1','1','1','1','0'],[1700000000002,'1','1','1','1','0']];
    raises(InvalidArgumentException::class, function () use (&$rows) { normalize_ticker($rows,true); });
    normalize_ticker($rows,true,'milliseconds'); eq(array_keys($rows),[1700000000001,1700000000002]);
});
test('unknown index unit is rejected', function () {
    $rows=[]; raises(InvalidArgumentException::class,function () use (&$rows) { normalize_ticker($rows,true,'microseconds'); });
});
test('SMA EMA SMMA golden fixtures', function () {
    $rows=candles(['1','2','3','4']); $i=new Indicators();
    $sma=$i->sma($rows,2); $ema=$i->ema($rows,3); $smma=$i->smma($rows,3);
    foreach (['1','1.5','2.5','3.5'] as $p=>$expected) decimal($rows[$p][$sma],$expected);
    foreach (['1','1.5','2','3'] as $p=>$expected) decimal($rows[$p][$ema],$expected);
    near($rows[3][$smma],'2.6666666666666666');
});
test('tiny input changes survive averages and true range', function () {
    $rows=candles(['1e-20','2e-20','3e-20']); $i=new Indicators();
    $sma=$i->sma($rows,2); $ema=$i->ema($rows,2); $tr=$i->tr($rows);
    decimal($rows[1][$sma],'0.000000000000000000015',24);
    decimal($rows[1][$ema],'0.000000000000000000015',24);
    decimal($rows[1][$tr],'0.00000000000000000001',24);
});
test('true range includes the previous-close gap', function () {
    $i=new Indicators(); decimal($i->technical_true_range_value('12','11','9'),'3');
    decimal($i->technical_typical_price_value('12','9','9'),'10');
});
test('relative changes and scalar zero denominators', function () {
    $i=new Indicators(); decimal($i->technical_relative_change_value('12','10'),'0.2');
    eq($i->technical_relative_change_value('12','0'),null);
    eq($i->technical_normalized_difference_value('2','1','0'),null);
    decimal($i->technical_relative_change_value('2e-20','1e-20'),'1');
});
test('candle geometry is decimal and normalized', function () {
    $g=(new Indicators())->technical_candle_geometry('10','14','8','12');
    foreach ($g as $v) near($v,'0.3333333333333333');
});
test('RSI first Wilder seed and single smoothing pass', function () {
    $rows=candles(['10','11','10','12','11']); $key=(new Indicators())->rsi($rows,3);
    near($rows[3][$key],'75'); near($rows[4][$key],'54.54545454545455');
});
test('RSI neutral/rising/falling boundaries', function () {
    foreach ([[['1','1','1'],'50'],[['1','2','3'],'100'],[['3','2','1'],'0']] as [$prices,$expected]) {
        $rows=candles($prices); $key=(new Indicators())->rsi($rows,2); decimal(end($rows)[$key],$expected);
    }
});
test('price stochastic and stochastic RSI retain distinct flat conventions', function () {
    $rows=candles(array_fill(0,20,'1')); $i=new Indicators(); [$sto]=$i->sto($rows,3); [$rsi]=$i->sto_rsi($rows,3);
    decimal(end($rows)[$sto],'100'); decimal(end($rows)[$rsi],'50');
});
test('ATR defaults to Wilder and supports explicit alternatives', function () {
    $i=new Indicators(); $rows=candles(['1','2','3','4']);
    $default=$i->atr($rows,2); $sma=$i->atr($rows,2,'SMA'); $ema=$i->atr($rows,2,'ema');
    eq($default,'atr(2)'); decimal($rows[3][$default],'0.875'); decimal($rows[3][$sma],'1');
    near($rows[3][$ema],'0.9444444444444444');
    raises(InvalidArgumentException::class,function () use ($i,&$rows) { $i->atr($rows,2,'rsi'); });
});
test('Bollinger standard deviation retains squared tiny deltas', function () {
    $rows=candles(['1e-20','3e-20']); $i=new Indicators(); [$low,$high,$width,$std]=$i->bb($rows,2,1);
    decimal($rows[1][$std],'0.00000000000000000001',28);
    decimal($rows[1][$low],'0.00000000000000000001',28);
    decimal($rows[1][$high],'0.00000000000000000003',28);
});
test('anatomy flags preserve tiny non-doji bodies', function () {
    $rows=[[1700000000000,'1e-20','2e-20','1e-20','2e-20','1']]; normalize_ticker($rows);
    (new Indicators())->candle_anatomy($rows); eq($rows[0]['is_doji()'],0); eq($rows[0]['is_white_marubozu()'],1);
});
test('unfinished patterns remain explicitly quarantined', function () {
    $i=new Indicators(); $rows=[];
    raises(LogicException::class,function () use ($i,&$rows) { $i->is_dragonfly($rows); });
    raises(LogicException::class,function () use ($i,&$rows) { $i->is_grave_stone($rows); });
});
test('price speed respects an explicit interval', function () {
    $rows=candles(['1','3']); $key=(new Indicators())->price_speed($rows,1,'2');
    eq($key,'speed(1,2)'); decimal($rows[1][$key],'1');
});
test('price speed infers milliseconds without float arithmetic', function () {
    $rows=candles(['1','61']); $key=(new Indicators())->price_speed($rows);
    eq($key,'speed(1,60)'); decimal($rows[1][$key],'1');
});
test('helpers preserve keys and rename to self safely', function () {
    $rows=fixture(3); $keys=array_keys($rows); $i=new Indicators();
    $i->clone_key($rows,'close','copy'); $i->rename_key($rows,'copy','copy');
    foreach ($rows as $r) eq($r['copy'],$r['close']);
    $i->delete_key($rows,'copy'); eq(array_keys($rows),$keys);
});
test('ordinary arrays recalculate after source data changes', function () {
    $i=new Indicators(); $rows=candles(['1','2']); $key=$i->sma($rows,2);
    $rows[1]['close']='4'; $i->sma($rows,2); decimal($rows[1][$key],'2.5');
});
foreach (calls() as $method=>$args) {
    foreach (['normal','tiny','zero','empty'] as $kind) {
        test('smoke '.$method.' / '.$kind,function () use ($method,$args,$kind) {
            $i=new Indicators();
            $rows=match($kind) {'normal'=>fixture(35),'tiny'=>fixture(35,true),'zero'=>candles(array_fill(0,35,'0')),'empty'=>[]};
            $before=array_keys($rows);
            if ($method==='inflexion') $i->ema($rows);
            $result=$i->{$method}($rows,...$args);
            eq(array_keys($rows),$before);
            foreach ($rows as $r) foreach ($r as $v) yes(!is_float($v),'Float leaked from '.$method);
            if ($rows!==[] && $result!==null) foreach ((array)$result as $key) yes(array_key_exists($key,end($rows)),'Missing '.$key);
        });
    }
}
test('prefix calculations do not inspect future precision', function () {
    $rows=fixture(16); $rows[array_key_last($rows)]['close']='112.123456789012345678901234';
    // Only indicator arithmetic is under test; these rows are already normalized.
    foreach (['ema','sma','smma','cci','atrp'] as $method) {
        $i=new Indicators(); $full=$rows; $key=$i->{$method}($full,3);
        for ($n=1;$n<count($rows);$n++) {
            $prefix=array_slice($rows,0,$n,true); $i->{$method}($prefix,3);
            $last=array_key_last($prefix); eq($prefix[$last][$key],$full[$last][$key],$method.' prefix '.$n);
        }
    }
});
foreach ([null, new PrecisionContext(24,new PrecisionPolicy(16,4,32))] as $context) {
    test('streamed core equals complete arrays / '.($context?'fixed context':'adaptive'),function () use ($context) {
        $i=new Indicators($context); $input=fixture(210,true); $full=$input; $keys=pipeline($i,$full);
        $sliding=iterator_to_array($i->ticker_slide($input,function (array &$slice) use ($i) { pipeline($i,$slice); }, 14,3,23));
        eq(array_keys($sliding),array_keys($full));
        foreach ($full as $timestamp=>$row) {
            foreach ($keys as $key) eq($sliding[$timestamp][$key],$row[$key],$key.' at '.$timestamp);
            foreach (['__ticker_position','__ticker_seed','__ticker_cached'] as $internal) yes(!array_key_exists($internal,$sliding[$timestamp]));
        }
    });
}
test('slice overlap and arguments are validated',function () {
    $i=new Indicators(); $rows=fixture(4);
    raises(InvalidArgumentException::class,fn()=> $i->ticker_slice($rows,0));
    raises(InvalidArgumentException::class,fn()=> iterator_to_array($i->ticker_slide($rows,fn($x)=>null,3,batchSize:0)));
    raises(LogicException::class,fn()=>iterator_to_array($i->ticker_slide($rows,function(array &$x){$x=[];},3)));
});
test('gap callback resets recurrence state',function () {
    $i=new Indicators(); $rows=fixture(30); $keys=array_keys($rows); $split=$keys[15];
    $result=iterator_to_array($i->ticker_slide($rows,function(array &$x) use($i){$i->ema($x,3);},3,batchSize:7,
        continuous:static fn($previous,$next)=>$next['microtimestamp']!==$split));
    decimal($result[$split]['ema(3,close)'],$rows[$split]['close']);
});

test('zero inferred candle interval returns null', function () {
    $rows=candles(['1','2']); $rows[1]['microtimestamp']=$rows[0]['microtimestamp'];
    eq((new Indicators())->price_speed($rows),null);
});
test('insufficient logarithm precision is rejected explicitly', function () {
    $rows=fixture(3); $i=new Indicators(new PrecisionContext(0,new PrecisionPolicy(0,0,2)));
    raises(InvalidArgumentException::class,function () use ($i,&$rows) { $i->chop($rows,3); });
});
foreach (calls() as $method=>$args) {
    test('per-call context '.$method,function () use ($method,$args) {
        $i=new Indicators(); $context=new PrecisionContext(24,new PrecisionPolicy(16,4,32));
        $a=fixture(12); $b=$a;
        if ($method==='inflexion') { $i->ema($a); $i->ema($b); }
        $expected=$i->withPrecisionContext($context)->{$method}($a,...$args);
        $options=$args; $options['context']=$context;
        $actual=$i->{$method}($b,...$options);
        eq($actual,$expected); eq($b,$a); eq($i->technical_scale(),16);
    });
}
test('streamed composite indicators equal complete arrays',function () {
    $i=new Indicators(new PrecisionContext(24,new PrecisionPolicy(16,4,32)));
    $methods=['macd','ppo','sto','bb','keltner','super_trend','adx','chop','ao','ac','squeeze','min_max','ichimoku'];
    $config=calls();
    $calculate=function(array &$rows) use($i,$methods,$config): array {
        $keys=[];
        foreach ($methods as $method) array_push($keys,...(array)$i->{$method}($rows,...$config[$method]));
        return array_unique($keys);
    };
    $input=fixture(250,true); $full=$input; $keys=$calculate($full);
    $stream=iterator_to_array($i->ticker_slide($input,$calculate,34,3,29));
    foreach($full as $time=>$row) foreach($keys as $key) eq($stream[$time][$key],$row[$key],$key.' at '.$time);
});

test('global bcscale and old exchange constant cannot influence calculation', function () {
    define('EXCHANGE_ROUND_DECIMALS', 1);
    $previous=bcscale(3);
    try {
        $i=new Indicators(); $a=fixture(20); $i->min_max($a,3); $i->ichimoku($a,3,5,5,8); eq(bcscale(),3);
        eq($i->technical_scale(),16);
        $rows=candles(['1','2']); $key=$i->sma($rows,2); eq($rows[1][$key],'1.5000000000000000');
    } finally { bcscale($previous); }
});

$failures=0; $start=microtime(true);
foreach ($tests as $name=>$run) {
    try { $run(); echo 'PASS '.$name."\n"; }
    catch (Throwable $e) { $failures++; fwrite(STDERR,'FAIL '.$name.': '.$e::class.': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().")\n"); }
}
echo count($tests).' tests; '.$failures.' failures; '.number_format(microtime(true)-$start,2)." seconds.\n";
exit($failures===0?0:1);
