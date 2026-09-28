<?php

declare(strict_types=1);

namespace CryptoHistory;

use PDO;
use RuntimeException;

final class PriceStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->migrate();
    }

    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    public function assertInterval(string $interval): void
    {
        $existing = $this->getMeta('interval');
        if ($existing === null) {
            $this->setMeta('interval', $interval);

            return;
        }
        if ($existing !== $interval) {
            throw new RuntimeException(
                "Database holds {$existing} candles, but config interval is {$interval}. "
                . "Delete data/prices.sqlite or set the interval back to {$existing}."
            );
        }
    }

    /**
     * @param list<array{
     *   open_time: int,
     *   open: string,
     *   high: string,
     *   low: string,
     *   close: string,
     *   volume: string,
     *   close_time: int,
     *   quote_volume: string,
     *   trades: int
     * }> $candles
     */
    public function upsertCandles(string $symbol, string $pair, array $candles): int
    {
        if ($candles === []) {
            return 0;
        }

        $sql = 'INSERT INTO prices (
                    symbol, pair, open_time, close_time, open, high, low, close, volume, quote_volume, trades
                ) VALUES (
                    :symbol, :pair, :open_time, :close_time, :open, :high, :low, :close, :volume, :quote_volume, :trades
                )
                ON CONFLICT(symbol, open_time) DO UPDATE SET
                    pair = excluded.pair,
                    close_time = excluded.close_time,
                    open = excluded.open,
                    high = excluded.high,
                    low = excluded.low,
                    close = excluded.close,
                    volume = excluded.volume,
                    quote_volume = excluded.quote_volume,
                    trades = excluded.trades';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($candles as $candle) {
                $stmt->execute([
                    ':symbol' => $symbol,
                    ':pair' => $pair,
                    ':open_time' => $candle['open_time'],
                    ':close_time' => $candle['close_time'],
                    ':open' => $candle['open'],
                    ':high' => $candle['high'],
                    ':low' => $candle['low'],
                    ':close' => $candle['close'],
                    ':volume' => $candle['volume'],
                    ':quote_volume' => $candle['quote_volume'],
                    ':trades' => $candle['trades'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($candles);
    }

    public function earliestOpenTime(string $symbol): ?int
    {
        return $this->boundary($symbol, 'MIN');
    }

    public function latestOpenTime(string $symbol): ?int
    {
        return $this->boundary($symbol, 'MAX');
    }

    public function countSymbol(string $symbol): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM prices WHERE symbol = :symbol');
        $stmt->execute([':symbol' => $symbol]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Hour ranges present in the span but missing from the table.
     * Binance sometimes has no candle for an hour (exchange halt). Those
     * ranges stay empty, so callers should request only the gaps.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function missingRanges(string $symbol, int $intervalMs): array
    {
        $earliest = $this->earliestOpenTime($symbol);
        $latest = $this->latestOpenTime($symbol);
        if ($earliest === null || $latest === null || $intervalMs < 1) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT open_time FROM prices
             WHERE symbol = :symbol AND open_time >= :from_ms AND open_time <= :to_ms
             ORDER BY open_time ASC'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':from_ms', $earliest, PDO::PARAM_INT);
        $stmt->bindValue(':to_ms', $latest, PDO::PARAM_INT);
        $stmt->execute();

        $have = [];
        while ($row = $stmt->fetch()) {
            $have[(int) $row['open_time']] = true;
        }

        $ranges = [];
        $rangeStart = null;
        $previous = null;
        for ($cursor = $earliest; $cursor <= $latest; $cursor += $intervalMs) {
            if (isset($have[$cursor])) {
                if ($rangeStart !== null && $previous !== null) {
                    $ranges[] = [$rangeStart, $previous];
                    $rangeStart = null;
                    $previous = null;
                }
                continue;
            }
            if ($rangeStart === null) {
                $rangeStart = $cursor;
            }
            $previous = $cursor;
        }
        if ($rangeStart !== null && $previous !== null) {
            $ranges[] = [$rangeStart, $previous];
        }

        return $ranges;
    }

    public function countBetween(string $symbol, int $fromMs, int $toMs): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM prices
             WHERE symbol = :symbol AND open_time >= :from_ms AND open_time <= :to_ms'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':from_ms', $fromMs, PDO::PARAM_INT);
        $stmt->bindValue(':to_ms', $toMs, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findAt(string $symbol, int $openTimeMs): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT symbol, pair, open_time, close_time, open, high, low, close, volume, quote_volume, trades
             FROM prices WHERE symbol = :symbol AND open_time = :open_time'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':open_time', $openTimeMs, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRange(string $symbol, int $fromMs, int $toMs, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT symbol, pair, open_time, close_time, open, high, low, close, volume, quote_volume, trades
             FROM prices
             WHERE symbol = :symbol AND open_time >= :from_ms AND open_time <= :to_ms
             ORDER BY open_time ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':from_ms', $fromMs, PDO::PARAM_INT);
        $stmt->bindValue(':to_ms', $toMs, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, array{pair: string, candles: int, first_open: int, last_open: int}>
     */
    public function coverage(): array
    {
        $rows = $this->pdo->query(
            'SELECT symbol, pair, COUNT(*) AS candles, MIN(open_time) AS first_open, MAX(open_time) AS last_open
             FROM prices
             GROUP BY symbol'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['symbol']] = [
                'pair' => (string) $row['pair'],
                'candles' => (int) $row['candles'],
                'first_open' => (int) $row['first_open'],
                'last_open' => (int) $row['last_open'],
            ];
        }

        return $out;
    }

    private function boundary(string $symbol, string $fn): ?int
    {
        $stmt = $this->pdo->prepare("SELECT {$fn}(open_time) FROM prices WHERE symbol = :symbol");
        $stmt->execute([':symbol' => $symbol]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS prices (
                symbol TEXT NOT NULL,
                pair TEXT NOT NULL,
                open_time INTEGER NOT NULL,
                close_time INTEGER NOT NULL,
                open TEXT NOT NULL,
                high TEXT NOT NULL,
                low TEXT NOT NULL,
                close TEXT NOT NULL,
                volume TEXT NOT NULL,
                quote_volume TEXT NOT NULL,
                trades INTEGER NOT NULL,
                PRIMARY KEY (symbol, open_time)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );
    }
}
