<?php

declare(strict_types=1);

namespace CryptoHistory;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Named API tokens. Only the SHA-256 hash is stored. The raw token is
 * returned once from create() and cannot be read back.
 */
final class ApiTokens
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                token_hash TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL,
                last_used_at TEXT
            )'
        );
    }

    public function create(string $name): string
    {
        $name = $this->normalizeName($name);
        $token = 'ch_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (name, token_hash, created_at) VALUES (:name, :token_hash, :created_at)'
        );

        try {
            $stmt->execute([
                ':name' => $name,
                ':token_hash' => hash('sha256', $token),
                ':created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new InvalidArgumentException(
                    "A token named {$name} already exists. Revoke it before creating another."
                );
            }
            throw $e;
        }

        return $token;
    }

    /**
     * @return list<array{name: string, created_at: string, last_used_at: ?string}>
     */
    public function list(): array
    {
        $rows = $this->pdo->query(
            'SELECT name, created_at, last_used_at FROM api_tokens ORDER BY name'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => (string) $row['name'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            ];
        }

        return $out;
    }

    public function revoke(string $name): void
    {
        $name = $this->normalizeName($name);
        $stmt = $this->pdo->prepare('DELETE FROM api_tokens WHERE name = :name');
        $stmt->execute([':name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("No token named {$name}.");
        }
    }

    /**
     * True when $presented matches a stored token or the optional env token.
     * A match against a stored token updates last_used_at.
     */
    public function matches(string $presented, string $envToken = ''): bool
    {
        if ($presented === '' || strlen($presented) > 512) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM api_tokens WHERE token_hash = :token_hash');
        $stmt->execute([':token_hash' => hash('sha256', $presented)]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $touch = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = :last_used_at WHERE id = :id');
            $touch->bindValue(':last_used_at', gmdate('Y-m-d\TH:i:s\Z'));
            $touch->bindValue(':id', $id, PDO::PARAM_INT);
            $touch->execute();

            return true;
        }

        return $envToken !== '' && hash_equals($envToken, $presented);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'Token name must be 1-40 letters, numbers, dashes, or underscores.'
            );
        }

        return $name;
    }
}
