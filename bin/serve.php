#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$host = $argv[1] ?? '127.0.0.1:8080';
if (preg_match('/^[\w.\-]+:\d+$/', $host) !== 1) {
    fwrite(STDERR, "Usage: php bin/serve.php [host:port]\n");
    exit(1);
}

$root = dirname(__DIR__);
$public = $root . '/public';
$router = $public . '/index.php';
$command = escapeshellarg(PHP_BINARY)
    . ' -S ' . escapeshellarg($host)
    . ' -t ' . escapeshellarg($public)
    . ' ' . escapeshellarg($router);

fwrite(STDOUT, "Price API at http://{$host}\n");
fwrite(STDOUT, "Example: http://{$host}/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45\n");
passthru($command, $exit);
exit($exit);
