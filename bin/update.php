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

const UPDATE_HELP = <<<TXT
Save candles since the last stored bar. Run this once a day from cron.

Usage:
  php bin/update.php [--symbols=BTC,ETH]

Coins with no history yet get the last 48 candles only.
Run php bin/backfill.php first for the five-year load.

TXT;

try {
    $args = Cli::args($argv);
    if (isset($args['help'])) {
        fwrite(STDOUT, UPDATE_HELP);
        exit(0);
    }

    $config = crypto_config();
    $symbols = (new Symbols($config))->select(
        isset($args['symbols']) && is_string($args['symbols']) ? $args['symbols'] : null
    );
    $lock = new Lock((string) $config['lock_path']);
    $lock->acquire();
    crypto_sync($config)->update($symbols);
} catch (Throwable $e) {
    Cli::fail($e);
}
