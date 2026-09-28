<?php

declare(strict_types=1);

namespace CryptoHistory;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class HistorySync
{
    private const PAGE = 1000;

    /** @param callable(string): void|null $logger */
    public function __construct(
        private readonly BinanceClient $binance,
        private readonly PriceStore $store,
        private readonly Symbols $symbols,
        private readonly string $interval,
        private readonly int $intervalMs,
        private $logger = null,
    ) {
        $this->logger ??= static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
            fflush(STDOUT);
        };
    }

    /** @param list<string> $symbols */
    public function backfill(array $symbols, int $years, ?int $days = null): void
    {
        $this->store->assertInterval($this->interval);
        [$startMs, $endMs] = $this->window($years, $days);
        $failures = [];

        foreach ($symbols as $symbol) {
            try {
                $this->backfillSymbol($symbol, $startMs, $endMs);
            } catch (RuntimeException $e) {
                if (!$this->isUnknownPair($e)) {
                    throw $e;
                }
                $this->log('FAILED ' . $symbol . ': ' . $e->getMessage());
                $failures[] = $symbol;
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'Finished with failed symbols: ' . implode(', ', $failures)
                . '. Check the ticker is a Binance USDT spot pair.'
            );
        }
    }

    /** @param list<string> $symbols */
    public function update(array $symbols): void
    {
        $this->store->assertInterval($this->interval);
        $endMs = $this->nowMs();
        $failures = [];

        foreach ($symbols as $symbol) {
            try {
                $this->updateSymbol($symbol, $endMs);
            } catch (RuntimeException $e) {
                if (!$this->isUnknownPair($e)) {
                    throw $e;
                }
                $this->log('FAILED ' . $symbol . ': ' . $e->getMessage());
                $failures[] = $symbol;
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'Finished with failed symbols: ' . implode(', ', $failures)
                . '. Check the ticker is a Binance USDT spot pair.'
            );
        }
    }

    private function backfillSymbol(string $symbol, int $startMs, int $endMs): void
    {
        $pair = $this->symbols->pair($symbol);
        $this->log("{$symbol} ({$pair}) " . CandleTime::toIso($startMs) . ' → ' . CandleTime::toIso($endMs));

        $earliest = $this->store->earliestOpenTime($symbol);
        $latest = $this->store->latestOpenTime($symbol);

        if ($earliest === null || $latest === null) {
            $this->syncRange($symbol, $pair, $startMs, $endMs);
        } else {
            if ($earliest > $startMs + $this->intervalMs) {
                $this->syncRange($symbol, $pair, $startMs, $earliest);
                $latest = $this->store->latestOpenTime($symbol) ?? $latest;
            }

            if ($latest < $endMs - $this->intervalMs) {
                $this->syncRange($symbol, $pair, $latest, $endMs);
            } else {
                $this->log("{$symbol} already covers this range. Refreshing the latest candle.");
                $this->syncRange($symbol, $pair, $latest, $endMs);
            }
        }

        $this->fillGaps($symbol, $pair);
        $this->logSummary($symbol);
    }

    private function updateSymbol(string $symbol, int $endMs): void
    {
        $pair = $this->symbols->pair($symbol);
        $latest = $this->store->latestOpenTime($symbol);
        if ($latest === null) {
            $startMs = $endMs - (48 * $this->intervalMs);
            $this->log("{$symbol} ({$pair}) has no history. Saving the last 48 candles. Run bin/backfill.php for the full range.");
        } else {
            $startMs = $latest;
            $this->log("{$symbol} ({$pair}) updating from " . CandleTime::toIso($startMs));
        }

        $this->syncRange($symbol, $pair, $startMs, $endMs);
        $this->fillGaps($symbol, $pair);
        $this->logSummary($symbol);
    }

    private function fillGaps(string $symbol, string $pair): void
    {
        $ranges = $this->unacceptedGaps($symbol, $this->store->missingRanges($symbol, $this->intervalMs));
        if ($ranges === []) {
            return;
        }

        $missing = 0;
        foreach ($ranges as [$from, $to]) {
            $missing += (int) (($to - $from) / $this->intervalMs) + 1;
        }
        $this->log("{$symbol} is missing {$missing} candle(s). Requesting only those gaps.");
        foreach ($ranges as [$from, $to]) {
            $this->syncRange($symbol, $pair, $from, $to);
        }

        $still = $this->store->missingRanges($symbol, $this->intervalMs);
        $this->rememberGaps($symbol, $still);
        if ($still === []) {
            return;
        }

        $labels = [];
        foreach ($still as [$from, $to]) {
            $labels[] = $from === $to
                ? CandleTime::toIso($from)
                : CandleTime::toIso($from) . ' → ' . CandleTime::toIso($to);
        }
        $shown = array_slice($labels, 0, 5);
        $extra = count($labels) > 5 ? ' (+' . (count($labels) - 5) . ' more)' : '';
        $this->log(
            "{$symbol} still missing " . implode(', ', $shown) . $extra
            . '. Binance has no candle for those times.'
        );
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    private function unacceptedGaps(string $symbol, array $ranges): array
    {
        $accepted = $this->acceptedGapKeys($symbol);
        $pending = [];
        foreach ($ranges as $range) {
            if (!isset($accepted[$range[0] . ':' . $range[1]])) {
                $pending[] = $range;
            }
        }

        return $pending;
    }

    /** @return array<string, true> */
    private function acceptedGapKeys(string $symbol): array
    {
        $raw = $this->store->getMeta('gaps:' . $symbol);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_fill_keys(array_map(static fn ($key): string => (string) $key, $decoded), true);
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private function rememberGaps(string $symbol, array $ranges): void
    {
        $keys = [];
        foreach ($ranges as [$from, $to]) {
            $keys[] = $from . ':' . $to;
        }
        $this->store->setMeta('gaps:' . $symbol, json_encode($keys, JSON_THROW_ON_ERROR));
    }

    private function syncRange(string $symbol, string $pair, int $startMs, int $endMs): void
    {
        $cursor = $startMs;
        $previousLast = null;

        while ($cursor <= $endMs) {
            $rows = $this->binance->klines($pair, $this->interval, $cursor, $endMs, self::PAGE);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['open_time'] >= $cursor && $row['open_time'] <= $endMs
            ));

            if ($rows === []) {
                if ($previousLast === null) {
                    $this->log('  no candles in ' . CandleTime::toIso($cursor) . ' → ' . CandleTime::toIso($endMs));
                }
                break;
            }

            $last = $rows[count($rows) - 1]['open_time'];
            if ($previousLast !== null && $last <= $previousLast) {
                break;
            }

            $saved = $this->store->upsertCandles($symbol, $pair, $rows);
            $this->log("  saved {$saved} through " . CandleTime::toIso($last));

            $previousLast = $last;
            $next = $last + $this->intervalMs;
            if (count($rows) < self::PAGE || $next > $endMs) {
                break;
            }
            $cursor = $next;
        }
    }

    private function logSummary(string $symbol): void
    {
        $count = $this->store->countSymbol($symbol);
        $first = $this->store->earliestOpenTime($symbol);
        $last = $this->store->latestOpenTime($symbol);
        if ($first === null || $last === null) {
            $this->log("{$symbol} stored 0 candles");

            return;
        }
        $this->log("{$symbol} stored {$count} candles, " . CandleTime::toIso($first) . ' → ' . CandleTime::toIso($last));
    }

    /** @return array{0: int, 1: int} */
    private function window(int $years, ?int $days): array
    {
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($days !== null) {
            if ($days < 1 || $days > 3650) {
                throw new RuntimeException('--days must be between 1 and 3650');
            }
            $start = $end->modify("-{$days} days");
        } else {
            if ($years < 1 || $years > 15) {
                throw new RuntimeException('--years must be between 1 and 15');
            }
            $start = $end->modify("-{$years} years");
        }

        return [((int) $start->format('U')) * 1000, ((int) $end->format('U')) * 1000];
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function isUnknownPair(RuntimeException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Invalid symbol') || str_contains($message, 'HTTP 400');
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }
}
