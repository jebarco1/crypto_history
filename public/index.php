<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $local = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    if (is_file($local)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$config = crypto_config();
$pdo = crypto_database($config);
(new CryptoHistory\Api($config, new CryptoHistory\PriceStore($pdo), new CryptoHistory\ApiTokens($pdo)))->handle();
