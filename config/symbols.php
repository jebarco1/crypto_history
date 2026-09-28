<?php

declare(strict_types=1);

/**
 * Coins stored in the local database.
 * Each ticker is fetched as a Binance {TICKER}USDT spot pair.
 * Add or remove lines, then run: php bin/backfill.php
 *
 * A coin listed less than five years ago is stored from its Binance listing date.
 */
return [
    'BTC',
    'ETH',
    'BNB',
    'XRP',
    'ADA',
    'DOGE',
    'SOL',
    'DOT',
    'LTC',
    'LINK',
    'AVAX',
    'BCH',
    'TRX',
    'UNI',
    'ATOM',
];
