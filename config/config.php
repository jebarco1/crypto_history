<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    // MySQL database on the host. Password comes from DB_PASSWORD in .env.
    'db_driver' => getenv('DB_DRIVER') ?: 'mysql',
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int) (getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: 'u111742749_cyrptohistory',
    'db_user' => getenv('DB_USER') ?: 'u111742749_cyrptohistory',
    'db_password' => getenv('DB_PASSWORD') ?: '',

    // Used only when DB_DRIVER=sqlite. Also the folder for the sync lock.
    'db_path' => getenv('CRYPTO_DB_PATH') ?: ($root . '/data/prices.sqlite'),
    'lock_path' => $root . '/data/sync.lock',

    // Public market-data host. Same klines as api.binance.com.
    'binance_base' => getenv('CRYPTO_BINANCE_BASE') ?: 'https://data-api.binance.vision',

    // Optional. Sent as X-MBX-APIKEY when downloading candles. Leave empty to
    // use the public endpoint with no key. Set BINANCE_API_KEY in .env.
    'binance_api_key' => getenv('BINANCE_API_KEY') ?: '',

    // Candle size stored in the database. 1h lets a date+time lookup
    // return the hour that contains that timestamp.
    // Supported: 1m, 5m, 15m, 1h, 4h, 1d
    // Changing this after data is loaded requires clearing the prices table.
    'interval' => getenv('CRYPTO_INTERVAL') ?: '1h',

    'years' => (int) (getenv('CRYPTO_YEARS') ?: 5),
    'quote' => 'USDT',

    // Optional extra token accepted alongside tokens created by bin/token.php.
    // Price routes always require a token. This env value is one way to set one.
    'api_token' => getenv('CRYPTO_API_TOKEN') ?: '',

    // Pause between Binance calls so a full backfill stays under the rate limit.
    'request_pause_us' => 150000,

    'symbols' => require __DIR__ . '/symbols.php',

    // Use when the Binance pair is not {TICKER}USDT.
    'pair_overrides' => [
        // 'MATIC' => 'MATICUSDT',
    ],
];
