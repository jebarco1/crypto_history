<?php

declare(strict_types=1);

namespace CryptoHistory;

use JsonException;
use RuntimeException;

/**
 * Public Binance spot klines.
 * Each candle is [open time, open, high, low, close, volume, close time, ...].
 * An API key is optional and is sent as X-MBX-APIKEY when set.
 */
final class BinanceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $pauseUs = 150000,
        private readonly string $apiKey = '',
    ) {
    }

    /**
     * @return list<array{
     *   open_time: int,
     *   open: string,
     *   high: string,
     *   low: string,
     *   close: string,
     *   volume: string,
     *   close_time: int,
     *   quote_volume: string,
     *   trades: int
     * }>
     */
    public function klines(string $pair, string $interval, int $startMs, int $endMs, int $limit = 1000): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v3/klines?' . http_build_query([
            'symbol' => $pair,
            'interval' => $interval,
            'startTime' => $startMs,
            'endTime' => $endMs,
            'limit' => $limit,
        ]);

        try {
            $decoded = json_decode($this->get($url), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Binance returned invalid JSON for {$pair}: " . $e->getMessage());
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['msg'] ?? 'unexpected response') : 'unexpected response';
            throw new RuntimeException("Binance error for {$pair}: {$message}");
        }

        $rows = [];
        foreach ($decoded as $kline) {
            if (!is_array($kline) || count($kline) < 9) {
                continue;
            }
            $rows[] = [
                'open_time' => (int) $kline[0],
                'open' => (string) $kline[1],
                'high' => (string) $kline[2],
                'low' => (string) $kline[3],
                'close' => (string) $kline[4],
                'volume' => (string) $kline[5],
                'close_time' => (int) $kline[6],
                'quote_volume' => (string) $kline[7],
                'trades' => (int) $kline[8],
            ];
        }

        return $rows;
    }

    private function get(string $url): string
    {
        $delay = 1;
        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                [$status, $headers, $body] = $this->request($url);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                sleep($delay);
                $delay = min($delay * 2, 30);
                continue;
            }

            if ($status === 200) {
                $used = (int) ($headers['x-mbx-used-weight-1m'] ?? 0);
                if ($used >= 1100) {
                    sleep(10);
                } else {
                    usleep($this->pauseUs);
                }

                return $body;
            }

            $lastError = "HTTP {$status}: " . substr($body, 0, 300);
            $retry = $status === 429 || $status === 418 || $status >= 500;
            if (!$retry || $attempt === 5) {
                throw new RuntimeException('Binance ' . $lastError);
            }

            $retryAfter = (int) ($headers['retry-after'] ?? 0);
            sleep($retryAfter > 0 ? $retryAfter : $delay);
            $delay = min($delay * 2, 30);
        }

        throw new RuntimeException('Binance ' . $lastError);
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function request(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to start a Binance request');
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: crypto-history/1.0',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'X-MBX-APIKEY: ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Binance request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, $this->parseHeaders(substr($raw, 0, $headerSize)), substr($raw, $headerSize)];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
