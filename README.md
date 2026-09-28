# Crypto history

Local PHP service that stores Binance spot prices and returns them by coin, date, and time.

Hourly USDT candles are saved in SQLite. A lookup at `13:45` returns the `13:00`–`14:00` candle. `price` is that candle’s close. `open`, `high`, `low`, and `close` are the rest of the bar. Times are UTC.

Candle data comes from Binance's public market-data host, so Binance does not need a key. The lookup API requires a token you create locally.

## Requirements

- PHP 8.1 or newer
- Extensions: `pdo_sqlite`, `curl`

## Coin list

Edit `config/symbols.php`. Each ticker is loaded as a `{TICKER}USDT` pair. If a coin listed on Binance less than five years ago, history starts at the listing date.

```php
return [
    'BTC',
    'ETH',
    'SOL',
];
```

Use `pair_overrides` in `config/config.php` when the Binance symbol is not `{TICKER}USDT`.

## Load five years

```bash
php bin/backfill.php
```

That walks every coin in the list and saves hourly candles for the last five years. Run it again after you add a coin. Candles already stored are kept.

Useful limits while testing:

```bash
php bin/backfill.php --symbols=BTC,ETH
php bin/backfill.php --symbols=BTC --days=30
php bin/backfill.php --years=5
```

## Daily update

```bash
php bin/update.php
```

This saves every candle since the last stored hour, including the hour that was still open on the previous run. Put it on cron once a day:

```cron
15 0 * * * /usr/bin/php /path/to/crypto_history/bin/update.php >> /path/to/crypto_history/data/update.log 2>&1
```

If a coin has never been backfilled, the daily script only saves the last 48 hours and tells you to run the backfill.

## API token

Price, range, and symbol routes require a token. Health and the route index stay open. The token is stored as a hash. The raw value is printed once.

```bash
php bin/token.php create --name=laptop
php bin/token.php list
php bin/token.php revoke --name=laptop
```

Send it on each request:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45"
```

`X-Api-Key: YOUR_TOKEN` is also accepted. A token in the query string is ignored.

`CRYPTO_API_TOKEN` is an extra accepted token when you would rather set one in the environment than create a named token. Named tokens can be revoked. The environment token cannot.

## Lookup API

From the project directory:

```bash
php bin/serve.php
```

The server listens on `http://127.0.0.1:8080`. Point Apache or nginx at the `public/` directory if you would rather not use the built-in server. `public/.htaccess` is included for Apache.

### One price

`symbol`, `date`, and `time` are required. `time` is `HH:MM` or `HH:MM:SS` in UTC.

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45"
```

```json
{
    "symbol": "BTC",
    "pair": "BTCUSDT",
    "interval": "1h",
    "timezone": "UTC",
    "requested_at": "2024-06-01T13:45:00Z",
    "basis": "close",
    "open_time": "2024-06-01T13:00:00Z",
    "close_time": "2024-06-01T13:59:59Z",
    "open_time_ms": 1717246800000,
    "price": "67720.01000000",
    "open": "67717.76000000",
    "high": "67837.00000000",
    "low": "67714.28000000",
    "close": "67720.01000000",
    "volume": "419.26976000",
    "quote_volume": "28424665.05663160",
    "trades": 22898
}
```

A time Binance never published (a trading halt) returns 404. The coin is still stored for the surrounding hours.

The same lookup accepts a single timestamp, or a path:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price?symbol=BTC&at=2024-06-01T13:45:00Z"
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price/BTC/2024-06-01/13-45"
```

### A range

`from` and `to` accept a date (`2024-06-01`) or a timestamp. A date-only `to` includes the whole UTC day. Results are paged. `limit` defaults to 500 and cannot be higher than 5000. Pass `next_from` back in as `from` for the next page.

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/prices?symbol=ETH&from=2024-06-01&to=2024-06-03&limit=100"
```

### Which coins are loaded

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://127.0.0.1:8080/api/v1/symbols"
```

`GET /` lists the routes. `GET /api/v1/health` reports that the process is up. Neither requires a token.

Coins not listed in `config/symbols.php` return 404. A time with no stored candle returns 404.

## Candle size

`interval` in `config/config.php` defaults to `1h`, which is what makes a time lookup useful. `1m`, `5m`, `15m`, `4h`, and `1d` also work. One-minute history for five years is a much larger download. After any interval change, delete `data/prices.sqlite` and run the backfill again.

## Tests

```bash
php tests/run.php
```
