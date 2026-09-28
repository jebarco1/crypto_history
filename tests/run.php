<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CryptoHistory\Api;
use CryptoHistory\ApiTokens;
use CryptoHistory\CandleTime;
use CryptoHistory\Database;
use CryptoHistory\PriceStore;
use CryptoHistory\Symbols;

$failed = 0;

function check(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        fwrite(STDOUT, "ok  {$message}\n");

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    $failed++;
}

function check_same(mixed $expected, mixed $actual, string $message): void
{
    check(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

$envFile = sys_get_temp_dir() . '/crypto-history-env-' . getmypid();
file_put_contents($envFile, "CRYPTO_HISTORY_TEST_KEY=\"abc-123\"\n# comment\nCRYPTO_HISTORY_TEST_KEEP=replaced\n");
putenv('CRYPTO_HISTORY_TEST_KEEP=original');
crypto_load_env($envFile);
check_same('abc-123', getenv('CRYPTO_HISTORY_TEST_KEY'), 'env file loads a quoted value');
check_same('original', getenv('CRYPTO_HISTORY_TEST_KEEP'), 'env file does not override existing variables');
putenv('CRYPTO_HISTORY_TEST_KEY');
putenv('CRYPTO_HISTORY_TEST_KEEP');
@unlink($envFile);

try {
    CandleTime::intervalMs('2h');
    check(false, 'unknown interval throws');
} catch (InvalidArgumentException) {
    check(true, 'unknown interval throws');
}

check_same(3_600_000, CandleTime::intervalMs('1h'), '1h interval');

$at = CandleTime::parseClock('2024-06-01', '13:45');
$hour = CandleTime::floorMs($at, CandleTime::intervalMs('1h'));
check_same('2024-06-01T13:00:00Z', CandleTime::toIso($hour), '13:45 floors to 13:00');
check_same('2024-06-01T13:45:00Z', CandleTime::toIso($at), 'clock parse');

$iso = CandleTime::parseStart('2024-06-01T13:45:00Z');
check_same($at, $iso, 'ISO Z parse matches clock');

$offset = CandleTime::parseStart('2024-06-01T09:45:00-04:00');
check_same($at, $offset, 'offset converts to UTC');

$dayStart = CandleTime::parseStart('2024-06-01');
$dayEnd = CandleTime::parseEnd('2024-06-01');
check_same('2024-06-01T00:00:00Z', CandleTime::toIso($dayStart), 'date start');
check_same('2024-06-01T23:59:59Z', CandleTime::toIso($dayEnd), 'date end');
check($dayEnd > $dayStart, 'end of day is after start');

try {
    CandleTime::parseStart('2024-13-40');
    check(false, 'invalid date throws');
} catch (InvalidArgumentException) {
    check(true, 'invalid date throws');
}

try {
    CandleTime::parseClock('2024-06-01', '25:00');
    check(false, 'invalid clock throws');
} catch (InvalidArgumentException) {
    check(true, 'invalid clock throws');
}

$dbPath = sys_get_temp_dir() . '/crypto-history-test-' . getmypid() . '.sqlite';
@unlink($dbPath);
$pdo = Database::connect([
    'db_driver' => 'sqlite',
    'db_path' => $dbPath,
]);
$store = new PriceStore($pdo);
$tokens = new ApiTokens($pdo);
$store->assertInterval('1h');

$open = $hour;
$store->upsertCandles('BTC', 'BTCUSDT', [
    candle($open, '100', '110', '90', '105', '1'),
    candle($open + 3_600_000, '105', '120', '100', '115', '2'),
    candle($open + 7_200_000, '115', '130', '110', '125', '3'),
]);
$store->upsertCandles('BTC', 'BTCUSDT', [
    candle($open, '100', '111', '90', '106', '9'),
]);

$found = $store->findAt('BTC', $open);
check_same('106', $found['close'] ?? null, 'upsert replaces close');
check_same(3, $store->countSymbol('BTC'), 'three candles stored');
check_same(2, $store->countBetween('BTC', $open, $open + 3_600_000), 'count between');

$range = $store->findRange('BTC', $open, $open + 7_200_000, 10);
check_same(3, count($range), 'range returns three');
check_same((string) ($open + 7_200_000), (string) $range[2]['open_time'], 'range is ordered');

$coverage = $store->coverage();
check_same(3, $coverage['BTC']['candles'] ?? null, 'coverage count');

$gaps = $store->missingRanges('BTC', 3_600_000);
check_same([], $gaps, 'contiguous hours have no gaps');
$store->upsertCandles('SOL', 'SOLUSDT', [
    candle($open, '1', '1', '1', '1', '1'),
    candle($open + 10_800_000, '2', '2', '2', '2', '2'),
]);
$solGaps = $store->missingRanges('SOL', 3_600_000);
check_same(1, count($solGaps), 'one missing range');
check_same($open + 3_600_000, $solGaps[0][0] ?? null, 'gap starts at the first missing hour');
check_same($open + 7_200_000, $solGaps[0][1] ?? null, 'gap ends at the last missing hour');

$config = [
    'db_path' => $dbPath,
    'interval' => '1h',
    'quote' => 'USDT',
    'api_token' => '',
    'symbols' => ['BTC', 'ETH'],
    'pair_overrides' => [],
    'binance_base' => 'https://data-api.binance.vision',
    'request_pause_us' => 0,
    'years' => 5,
];

$testToken = $tokens->create('test');
check(str_starts_with($testToken, 'ch_'), 'created token has the ch_ prefix');
check_same(46, strlen($testToken), 'created token length');
try {
    $tokens->create('test');
    check(false, 'duplicate token name rejected');
} catch (InvalidArgumentException) {
    check(true, 'duplicate token name rejected');
}
try {
    $tokens->create('bad name');
    check(false, 'invalid token name rejected');
} catch (InvalidArgumentException) {
    check(true, 'invalid token name rejected');
}

$api = new Api($config, $store, $tokens);
[$status] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45', false);
check_same(401, $status, 'price without a token is 401');
[$status, $body] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45');
check_same(200, $status, 'price status');
check_same('106', $body['price'] ?? null, 'price is the hourly close');
check_same('2024-06-01T13:00:00Z', $body['open_time'] ?? null, 'candle open');
check_same('111', $body['high'] ?? null, 'high survived upsert');
check_same('2024-06-01T13:45:00Z', $body['requested_at'] ?? null, 'requested time echoed');

[$status, $body] = call_api($api, '/api/v1/price/BTC/2024-06-01/13-45');
check_same(200, $status, 'path price status');
check_same('106', $body['price'] ?? null, 'path price');

[$status, $body] = call_api($api, '/api/v1/price?symbol=btc&at=2024-06-01T13:45:00Z');
check_same(200, $status, 'at= lookup');
check_same('BTC', $body['symbol'] ?? null, 'symbol normalized');

[$status, $body] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=16:10');
check_same(404, $status, 'missing hour is 404');
check(isset($body['error']), '404 has error');

[$status] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01');
check_same(400, $status, 'time is required');

[$status] = call_api($api, '/api/v1/price?symbol=DOGE&date=2024-06-01&time=13:45');
check_same(404, $status, 'unlisted symbol is 404');

[$status, $body] = call_api($api, '/api/v1/prices?symbol=BTC&from=2024-06-01&to=2024-06-01&limit=2');
check_same(200, $status, 'range status');
check_same(2, $body['count'] ?? null, 'limit pages the range');
check_same('2024-06-01T15:00:00Z', $body['next_from'] ?? null, 'next_from is the following candle');
check_same('106', $body['prices'][0]['price'] ?? null, 'first page price');

[$status, $body] = call_api($api, '/api/v1/prices?symbol=BTC&from=' . rawurlencode((string) $body['next_from']) . '&to=2024-06-01T23:59:59Z');
check_same(200, $status, 'second page status');
check_same(1, $body['count'] ?? null, 'second page has the last candle');
check(array_key_exists('next_from', $body) && $body['next_from'] === null, 'second page has no cursor');

[$status, $body] = call_api($api, '/api/v1/symbols');
check_same(200, $status, 'symbols status');
check_same('BTC', $body['symbols'][0]['symbol'] ?? null, 'symbols lists BTC');
check_same(0, $body['symbols'][1]['candles'] ?? null, 'ETH is listed with no candles');

[$status, $body] = call_api($api, '/api/v1');
check_same(200, $status, 'index status');
check(isset($body['endpoints']['price']), 'index documents the price route');

[$status] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45', 'wrong-token');
check_same(401, $status, 'wrong token is 401');
[$status] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45&api_key=' . rawurlencode($testToken), false);
check_same(401, $status, 'query string token is ignored');
[$status, $body] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45', false, $testToken);
check_same(200, $status, 'X-Api-Key accepted');
check_same('106', $body['price'] ?? null, 'X-Api-Key price');

$used = $tokens->list();
check_same('test', $used[0]['name'] ?? null, 'token list shows the name');
check($used[0]['last_used_at'] !== null, 'successful auth records last use');
check(!str_contains(json_encode($used, JSON_THROW_ON_ERROR), $testToken), 'token list does not contain the secret');

$envConfig = $config;
$envConfig['api_token'] = 'env-secret';
$envApi = new Api($envConfig, $store, $tokens);
[$status, $body] = call_api($envApi, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45', 'env-secret');
check_same(200, $status, 'CRYPTO_API_TOKEN is accepted');
check_same('106', $body['price'] ?? null, 'env token price');

[$status] = call_api($api, '/api/v1/health', false);
check_same(200, $status, 'health stays open');
[$status, $body] = call_api($api, '/api/v1', false);
check_same('Authorization: Bearer <token>', $body['auth'] ?? null, 'index documents bearer auth');

$tokens->revoke('test');
[$status] = call_api($api, '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45');
check_same(401, $status, 'revoked token is rejected');
try {
    $tokens->revoke('test');
    check(false, 'revoking a missing token fails');
} catch (InvalidArgumentException) {
    check(true, 'revoking a missing token fails');
}

$symbols = new Symbols($config);
check_same(['BTC'], $symbols->select('btc'), 'symbol subset');
try {
    $symbols->select('DOGE');
    check(false, 'unknown symbol rejected');
} catch (InvalidArgumentException) {
    check(true, 'unknown symbol rejected');
}

@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
fwrite(STDOUT, "all tests passed\n");

/** @return array{open_time: int, open: string, high: string, low: string, close: string, volume: string, close_time: int, quote_volume: string, trades: int} */
function candle(int $openMs, string $open, string $high, string $low, string $close, string $volume): array
{
    return [
        'open_time' => $openMs,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'close' => $close,
        'volume' => $volume,
        'close_time' => $openMs + 3_600_000 - 1,
        'quote_volume' => '1000',
        'trades' => 10,
    ];
}

/**
 * @param string|false|null $bearer null sends $testToken, false sends no bearer
 * @return array{0: int, 1: array<string, mixed>}
 */
function call_api(Api $api, string $uri, string|false|null $bearer = null, ?string $apiKey = null): array
{
    global $testToken;

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_API_KEY']);
    $_GET = [];
    $query = parse_url($uri, PHP_URL_QUERY);
    if (is_string($query)) {
        parse_str($query, $_GET);
    }
    if ($bearer === null) {
        $bearer = $testToken;
    }
    if (is_string($bearer) && $bearer !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
    }
    if ($apiKey !== null) {
        $_SERVER['HTTP_X_API_KEY'] = $apiKey;
    }

    ob_start();
    $api->handle();
    $raw = ob_get_clean();
    $decoded = json_decode($raw === false ? '' : $raw, true);

    return [http_response_code() ?: 0, is_array($decoded) ? $decoded : ['raw' => $raw]];
}
