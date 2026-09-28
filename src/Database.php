<?php

declare(strict_types=1);

namespace CryptoHistory;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** @param array<string, mixed> $config */
    public static function connect(array $config): PDO
    {
        $driver = (string) ($config['db_driver'] ?? 'mysql');
        if ($driver === 'sqlite') {
            return self::sqlite((string) $config['db_path']);
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException("Unsupported DB_DRIVER {$driver}. Use mysql or sqlite.");
        }

        return self::mysql($config);
    }

    private static function sqlite(string $path): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('PHP extension pdo_sqlite is required for DB_DRIVER=sqlite.');
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create database directory {$dir}");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /** @param array<string, mixed> $config */
    private static function mysql(array $config): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PHP extension pdo_mysql is required. Install php-mysql.');
        }

        $host = (string) ($config['db_host'] ?? 'localhost');
        $port = (int) ($config['db_port'] ?? 3306);
        $name = (string) ($config['db_name'] ?? '');
        $user = (string) ($config['db_user'] ?? '');
        $password = (string) ($config['db_password'] ?? '');

        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new RuntimeException('DB_HOST contains unsupported characters.');
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $user) !== 1) {
            throw new RuntimeException('DB_NAME and DB_USER may contain only letters, numbers, and underscores.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('DB_PORT must be between 1 and 65535.');
        }
        if ($password === '') {
            throw new RuntimeException(
                "Set DB_PASSWORD in .env for MySQL user {$user} on database {$name}."
            );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to MySQL database {$name} as {$user} on {$host}. "
                . 'Check DB_HOST, DB_PORT, and DB_PASSWORD in .env. '
                . $e->getMessage()
            );
        }
    }
}
