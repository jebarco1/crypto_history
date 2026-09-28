<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    // SQLite file created on the first backfill or update.
    'db_path' => getenv('CRYPTO_DB_PATH') ?: ($root . '/data/prices.sqlite'),

    // Public market-data host. Same klines as api.binance.com.
    'binance_base' => getenv('CRYPTO_BINANCE_BASE') ?: 'https://data-api.binance.vision',

    // Optional. Sent as X-MBX-APIKEY when downloading candles. Leave empty to
    // use the public endpoint with no key. Set BINANCE_API_KEY in .env.
    'binance_api_key' => getenv('BINANCE_API_KEY') ?: '',

    // Candle size stored in the database. 1h lets a date+time lookup
    // return the hour that contains that timestamp.
    // Supported: 1m, 5m, 15m, 1h, 4h, 1d
    // Changing this after data is loaded requires deleting data/prices.sqlite.
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
