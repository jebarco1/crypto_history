<?php

declare(strict_types=1);

namespace CryptoHistory;

final class Api
{
    private readonly Symbols $symbols;
    private readonly string $interval;
    private readonly int $intervalMs;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly PriceStore $store,
        private readonly ApiTokens $tokens,
    ) {
        $this->symbols = new Symbols($config);
        $stored = $store->getMeta('interval');
        $configured = (string) $config['interval'];
        if ($stored !== null && $stored !== $configured) {
            throw new HttpException(
                500,
                "Database holds {$stored} candles, but config interval is {$configured}."
            );
        }
        $this->interval = $stored ?? $configured;
        $this->intervalMs = CandleTime::intervalMs($this->interval);
    }

    public function handle(): void
    {
        http_response_code(200);
        $this->sendHeaders();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            http_response_code(204);

            return;
        }

        try {
            if ($method !== 'GET' && $method !== 'HEAD') {
                throw new HttpException(405, 'Only GET is supported');
            }
            $this->dispatch($this->path());
        } catch (HttpException $e) {
            $this->error($e->status(), $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->error(400, $e->getMessage());
        } catch (\Throwable $e) {
            $this->error(500, $e->getMessage());
        }
    }

    private function dispatch(string $path): void
    {
        $open = ['/', '/api', '/api/v1', '/api/v1/health'];
        if (!in_array($path, $open, true)) {
            $this->authorize();
        }

        if ($path === '/' || $path === '/api' || $path === '/api/v1') {
            $this->json($this->index());

            return;
        }
        if ($path === '/api/v1/health') {
            $this->json([
                'ok' => true,
                'interval' => $this->interval,
                'database' => is_file((string) $this->config['db_path']),
            ]);

            return;
        }
        if ($path === '/api/v1/symbols') {
            $this->json($this->symbolList());

            return;
        }
        if ($path === '/api/v1/price') {
            $this->priceFromQuery();

            return;
        }
        if ($path === '/api/v1/prices') {
            $this->pricesFromQuery();

            return;
        }
        if (preg_match('#^/api/v1/price/([A-Za-z0-9]+)/(\d{4}-\d{2}-\d{2})/(\d{2})-(\d{2})(?:-(\d{2}))?$#', $path, $m) === 1) {
            $time = $m[3] . ':' . $m[4] . ':' . ($m[5] ?? '00');
            $this->json($this->lookup($m[1], CandleTime::parseClock($m[2], $time)));

            return;
        }

        throw new HttpException(404, 'No route for ' . $path);
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        return [
            'name' => 'crypto-history',
            'interval' => $this->interval,
            'timezone' => 'UTC',
            'auth' => 'Authorization: Bearer <token>',
            'endpoints' => [
                'symbols' => '/api/v1/symbols',
                'price' => '/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45',
                'price_at' => '/api/v1/price?symbol=BTC&at=2024-06-01T13:45:00Z',
                'price_path' => '/api/v1/price/BTC/2024-06-01/13-45',
                'prices' => '/api/v1/prices?symbol=BTC&from=2024-06-01&to=2024-06-07',
                'health' => '/api/v1/health',
            ],
        ];
    }

    private function priceFromQuery(): void
    {
        $hasAt = isset($_GET['at']) && $_GET['at'] !== '';
        $hasDate = isset($_GET['date']) && $_GET['date'] !== '';
        $hasTime = isset($_GET['time']) && $_GET['time'] !== '';
        if ($hasAt && ($hasDate || $hasTime)) {
            throw new HttpException(400, 'Pass either at, or date and time, not both');
        }

        if ($hasAt) {
            $atMs = CandleTime::parseStart((string) $_GET['at']);
        } else {
            if (!$hasDate || !$hasTime) {
                throw new HttpException(400, 'symbol, date, and time are required. Example: ?symbol=BTC&date=2024-06-01&time=13:45');
            }
            $atMs = CandleTime::parseClock((string) $_GET['date'], (string) $_GET['time']);
        }

        $symbol = $this->querySymbol();
        $this->json($this->lookup($symbol, $atMs));
    }

    private function pricesFromQuery(): void
    {
        $symbol = $this->querySymbol();
        $fromRaw = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
        $toRaw = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
        if ($fromRaw === '' || $toRaw === '') {
            throw new HttpException(400, 'symbol, from, and to are required. Example: ?symbol=BTC&from=2024-06-01&to=2024-06-07');
        }

        $fromMs = CandleTime::parseStart($fromRaw);
        $toMs = CandleTime::parseEnd($toRaw);
        if ($fromMs > $toMs) {
            throw new HttpException(400, 'from must be before to');
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
        if ($limit < 1 || $limit > 5000) {
            throw new HttpException(400, 'limit must be between 1 and 5000');
        }

        $rows = $this->store->findRange($symbol, $fromMs, $toMs, $limit + 1);
        $nextFrom = null;
        if (count($rows) > $limit) {
            $extra = array_pop($rows);
            $nextFrom = CandleTime::toIso((int) $extra['open_time']);
        }

        $this->json([
            'symbol' => $symbol,
            'pair' => $this->symbols->pair($symbol),
            'interval' => $this->interval,
            'timezone' => 'UTC',
            'from' => CandleTime::toIso($fromMs),
            'to' => CandleTime::toIso($toMs),
            'count' => count($rows),
            'next_from' => $nextFrom,
            'prices' => array_map(fn (array $row): array => $this->candle($row), $rows),
        ]);
    }

    /** @return array<string, mixed> */
    private function lookup(string $symbol, int $atMs): array
    {
        $symbol = $this->requireSymbol($symbol);
        $openMs = CandleTime::floorMs($atMs, $this->intervalMs);
        $row = $this->store->findAt($symbol, $openMs);
        if ($row === null) {
            $when = CandleTime::toIso($openMs);
            $hint = $this->store->countSymbol($symbol) === 0
                ? ' Run bin/backfill.php to load this coin.'
                : ' Binance has no candle for that time.';
            throw new HttpException(404, 'No ' . $this->interval . ' price for ' . $symbol . ' at ' . $when . '.' . $hint);
        }

        return [
            'symbol' => $symbol,
            'pair' => (string) $row['pair'],
            'interval' => $this->interval,
            'timezone' => 'UTC',
            'requested_at' => CandleTime::toIso($atMs),
            'basis' => 'close',
        ] + $this->candle($row);
    }

    /** @return array<string, mixed> */
    private function symbolList(): array
    {
        $coverage = $this->store->coverage();
        $symbols = [];
        foreach ($this->symbols->all() as $symbol) {
            $row = $coverage[$symbol] ?? null;
            $symbols[] = [
                'symbol' => $symbol,
                'pair' => $row['pair'] ?? $this->symbols->pair($symbol),
                'candles' => $row['candles'] ?? 0,
                'from' => isset($row['first_open']) ? CandleTime::toIso($row['first_open']) : null,
                'to' => isset($row['last_open']) ? CandleTime::toIso($row['last_open']) : null,
            ];
        }

        return [
            'interval' => $this->interval,
            'quote' => (string) $this->config['quote'],
            'timezone' => 'UTC',
            'symbols' => $symbols,
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function candle(array $row): array
    {
        return [
            'open_time' => CandleTime::toIso((int) $row['open_time']),
            'close_time' => CandleTime::toIso((int) $row['close_time']),
            'open_time_ms' => (int) $row['open_time'],
            'price' => (string) $row['close'],
            'open' => (string) $row['open'],
            'high' => (string) $row['high'],
            'low' => (string) $row['low'],
            'close' => (string) $row['close'],
            'volume' => (string) $row['volume'],
            'quote_volume' => (string) $row['quote_volume'],
            'trades' => (int) $row['trades'],
        ];
    }

    private function querySymbol(): string
    {
        $symbol = isset($_GET['symbol']) ? trim((string) $_GET['symbol']) : '';
        if ($symbol === '') {
            throw new HttpException(400, 'symbol is required');
        }

        return $this->requireSymbol($symbol);
    }

    private function requireSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (!$this->symbols->has($symbol)) {
            throw new HttpException(404, "Symbol {$symbol} is not in config/symbols.php");
        }

        return $symbol;
    }

    private function authorize(): void
    {
        $presented = $this->presentedToken();
        $envToken = (string) ($this->config['api_token'] ?? '');
        if ($presented === '' || !$this->tokens->matches($presented, $envToken)) {
            throw new HttpException(401, 'Missing or invalid API token');
        }
    }

    private function presentedToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    private function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = rawurldecode($path);
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir)) ?: '/';
        }
        return '/' . trim($path, '/');
    }

    private function sendHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-Api-Key, Content-Type');
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function error(int $status, string $message): void
    {
        if (!headers_sent()) {
            $this->sendHeaders();
            if ($status === 401 && PHP_SAPI !== 'cli') {
                header('WWW-Authenticate: Bearer realm="crypto-history"');
            }
        }
        $this->json(['error' => $message], $status);
    }
}
