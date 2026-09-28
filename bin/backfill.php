#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use CryptoHistory\Cli;
use CryptoHistory\Lock;
use CryptoHistory\Symbols;

const BACKFILL_HELP = <<<TXT
Pull historical Binance USDT candles into the local database.

Usage:
  php bin/backfill.php [--symbols=BTC,ETH] [--years=5] [--days=60]

Options:
  --symbols   Comma-separated subset of config/symbols.php
  --years     Years of history to load (default 5, max 15)
  --days      If set, load this many days instead of --years

Edit the coin list in config/symbols.php before the first run.
Safe to run again: candles already stored are kept and gaps are filled.

TXT;

try {
    $args = Cli::args($argv);
    if (isset($args['help'])) {
        fwrite(STDOUT, BACKFILL_HELP);
        exit(0);
    }

    $config = crypto_config();
    $symbols = (new Symbols($config))->select(
        isset($args['symbols']) && is_string($args['symbols']) ? $args['symbols'] : null
    );
    $lock = new Lock((string) $config['lock_path']);
    $lock->acquire();

    $days = isset($args['days']) ? (int) $args['days'] : null;
    $years = isset($args['years']) ? (int) $args['years'] : (int) $config['years'];
    crypto_sync($config)->backfill($symbols, $years, $days);
} catch (Throwable $e) {
    Cli::fail($e);
}
