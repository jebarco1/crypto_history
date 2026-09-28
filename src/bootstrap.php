<?php

declare(strict_types=1);

foreach (['pdo_sqlite', 'curl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "PHP extension {$extension} is required.\n");
        exit(1);
    }
}

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CryptoHistory\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/**
 * Load KEY=value pairs from a file into the environment.
 * Variables already set in the process are left unchanged.
 */
function crypto_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

/** @return array<string, mixed> */
function crypto_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    crypto_load_env(dirname(__DIR__) . '/.env');

    /** @var array<string, mixed> $loaded */
    $loaded = require dirname(__DIR__) . '/config/config.php';
    $symbols = [];
    foreach ($loaded['symbols'] ?? [] as $symbol) {
        $symbols[] = strtoupper(trim((string) $symbol));
    }
    $loaded['symbols'] = array_values(array_unique($symbols));
    $loaded['quote'] = strtoupper((string) ($loaded['quote'] ?? 'USDT'));
    $loaded['interval'] = (string) ($loaded['interval'] ?? '1h');
    \CryptoHistory\CandleTime::intervalMs($loaded['interval']);

    if ($loaded['symbols'] === []) {
        throw new RuntimeException('config/symbols.php has no coins');
    }
    foreach ($loaded['symbols'] as $symbol) {
        if (preg_match('/^[A-Z0-9]{2,15}$/', $symbol) !== 1) {
            throw new RuntimeException("Invalid symbol in config/symbols.php: {$symbol}");
        }
    }

    $config = $loaded;

    return $config;
}

function crypto_database(?array $config = null): \PDO
{
    static $pdo = null;
    static $path = null;

    $config ??= crypto_config();
    $dbPath = (string) $config['db_path'];
    if ($pdo instanceof \PDO && $path === $dbPath) {
        return $pdo;
    }

    $pdo = \CryptoHistory\Database::connect($dbPath);
    $path = $dbPath;

    return $pdo;
}

function crypto_store(?array $config = null): \CryptoHistory\PriceStore
{
    $config ??= crypto_config();

    return new \CryptoHistory\PriceStore(crypto_database($config));
}

function crypto_sync(?array $config = null): \CryptoHistory\HistorySync
{
    $config ??= crypto_config();
    $interval = (string) $config['interval'];

    return new \CryptoHistory\HistorySync(
        new \CryptoHistory\BinanceClient(
            (string) $config['binance_base'],
            (int) $config['request_pause_us'],
            (string) ($config['binance_api_key'] ?? ''),
        ),
        crypto_store($config),
        new \CryptoHistory\Symbols($config),
        $interval,
        \CryptoHistory\CandleTime::intervalMs($interval),
    );
}
