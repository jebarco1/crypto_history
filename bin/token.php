#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use CryptoHistory\ApiTokens;
use CryptoHistory\Cli;

const TOKEN_HELP = <<<TXT
Create, list, and revoke API tokens. Price routes require one of these tokens.

Usage:
  php bin/token.php create --name=laptop
  php bin/token.php list
  php bin/token.php revoke --name=laptop

The raw token is printed once at creation and is not stored.
Send it as: Authorization: Bearer <token>
         or: X-Api-Key: <token>

TXT;

try {
    $command = $argv[1] ?? 'help';
    if ($command === '--help' || $command === 'help' || $command === '-h') {
        fwrite(STDOUT, TOKEN_HELP);
        exit(0);
    }
    if (!in_array($command, ['create', 'list', 'revoke'], true)) {
        fwrite(STDERR, "Unknown command {$command}.\n\n" . TOKEN_HELP);
        exit(1);
    }

    $args = Cli::args(array_merge([$argv[0]], array_slice($argv, 2)));
    $tokens = new ApiTokens(crypto_database());

    if ($command === 'list') {
        $rows = $tokens->list();
        if ($rows === []) {
            fwrite(STDOUT, "No tokens. Create one with: php bin/token.php create --name=laptop\n");
            exit(0);
        }
        foreach ($rows as $row) {
            $used = $row['last_used_at'] ?? 'never';
            fwrite(STDOUT, "{$row['name']}\tcreated {$row['created_at']}\tlast used {$used}\n");
        }
        exit(0);
    }

    $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : '';
    if ($name === '') {
        throw new InvalidArgumentException('Pass --name= for create and revoke.');
    }

    if ($command === 'create') {
        $token = $tokens->create($name);
        fwrite(STDOUT, "Token created for \"{$name}\".\n");
        fwrite(STDOUT, "Save this value now. It cannot be shown again.\n\n");
        fwrite(STDOUT, $token . "\n");
        exit(0);
    }

    $tokens->revoke($name);
    fwrite(STDOUT, "Revoked token \"{$name}\".\n");
} catch (Throwable $e) {
    Cli::fail($e);
}
