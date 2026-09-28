# Crypto history

Local PHP service that stores Binance spot prices and returns them by coin, date, and time.

Hourly USDT candles are saved in SQLite. A lookup at `13:45` returns the `13:00`–`14:00` candle. `price` is that candle’s close. `open`, `high`, `low`, and `close` are the rest of the bar. Times are UTC.

Two different keys are involved:

- **Binance API key** — optional. Used only while downloading candles from Binance.
- **Lookup token** — required. Used by your own API when you ask for a stored price. Create it with `php bin/token.php`. It is not a Binance key.

## Setup

### 1. Install PHP

PHP 8.1 or newer, with the `pdo_sqlite` and `curl` extensions.

Ubuntu or Debian:

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-curl
php -v
php -m | grep -E 'curl|pdo_sqlite'
```

### 2. Create the environment file

From the project directory:

```bash
cp .env.example .env
```

`.env` stays on this machine. It is listed in `.gitignore`.

### 3. Add a Binance API key

Candle history is public, so this step is optional. Add a key when a full five-year download is rate-limited, or when you want the requests tied to your Binance account. Leave `BINANCE_API_KEY` empty to download with no key.

1. Sign in at [Binance API Management](https://www.binance.com/en/my/settings/api-management).
2. Create an API key. Choose the system-generated key type. Label it `crypto-history`.
3. Confirm it with Binance (email, authenticator, or the other checks they show).
4. Copy the **API Key** into `.env`:

   ```
   BINANCE_API_KEY=paste-the-api-key-here
   ```

5. Binance also shows a **Secret Key** once. This app does not use it. Do not put the secret in `.env`. Keep it in a password manager only if another tool needs it.
6. Edit the key restrictions:
   - Turn **Enable Reading** on.
   - Leave spot trading, margin trading, and withdrawals off.
   - If Binance offers an IP allowlist, add the IP of the machine that runs `bin/backfill.php` and `bin/update.php`.

Save `.env`. The next `php bin/backfill.php` or `php bin/update.php` sends the key as the `X-MBX-APIKEY` header. You do not pass it on the command line.

### 4. Choose the coins

Edit `config/symbols.php`. Each ticker is loaded as a `{TICKER}USDT` pair. A coin listed on Binance less than five years ago is stored from its listing date.

```php
return [
    'BTC',
    'ETH',
    'SOL',
];
```

Use `pair_overrides` in `config/config.php` when the Binance symbol is not `{TICKER}USDT`.

### 5. Load five years of prices

```bash
php bin/backfill.php
```

That walks every coin in the list and saves hourly candles. Run it again after you add a coin. Candles already stored are kept.

Useful limits while testing:

```bash
php bin/backfill.php --symbols=BTC,ETH
php bin/backfill.php --symbols=BTC --days=30
php bin/backfill.php --years=5
```

The database file is `data/prices.sqlite`.

### 6. Create a lookup token

This token protects your API. It is separate from the Binance key.

```bash
php bin/token.php create --name=laptop
```

The command prints the token once. Copy it. Only a hash is stored.

```bash
php bin/token.php list
php bin/token.php revoke --name=laptop
```

To use one fixed token instead of a named one, put it in `.env`:

```
CRYPTO_API_TOKEN=choose-a-long-random-string
```

A named token can be revoked. `CRYPTO_API_TOKEN` cannot. You can set both. Either one is accepted.

### 7. Start the API

```bash
php bin/serve.php
```

The server listens on `http://127.0.0.1:8080`. Point Apache or nginx at the `public/` directory if you would rather not use the built-in server. `public/.htaccess` is included for Apache.

Ask for a price with the lookup token, not the Binance key:

```bash
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price?symbol=BTC&date=2024-06-01&time=13:45"
```

`X-Api-Key: YOUR_LOOKUP_TOKEN` is also accepted. A token in the query string is ignored.

### 8. Update prices every day

```bash
php bin/update.php
```

This saves every candle since the last stored hour, including the hour that was still open on the previous run. Cron once a day is enough. PHP reads `.env` itself, so the Binance key does not need to be exported in the crontab:

```cron
15 0 * * * /usr/bin/php /path/to/crypto_history/bin/update.php >> /path/to/crypto_history/data/update.log 2>&1
```

If a coin has never been backfilled, the daily script only saves the last 48 hours and tells you to run the backfill.

## Lookup API

`symbol`, `date`, and `time` are required. `time` is `HH:MM` or `HH:MM:SS` in UTC.

```bash
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
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
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price?symbol=BTC&at=2024-06-01T13:45:00Z"
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
  "http://127.0.0.1:8080/api/v1/price/BTC/2024-06-01/13-45"
```

### A range

`from` and `to` accept a date (`2024-06-01`) or a timestamp. A date-only `to` includes the whole UTC day. Results are paged. `limit` defaults to 500 and cannot be higher than 5000. Pass `next_from` back in as `from` for the next page.

```bash
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
  "http://127.0.0.1:8080/api/v1/prices?symbol=ETH&from=2024-06-01&to=2024-06-03&limit=100"
```

### Which coins are loaded

```bash
curl -H "Authorization: Bearer YOUR_LOOKUP_TOKEN" \
  "http://127.0.0.1:8080/api/v1/symbols"
```

`GET /` lists the routes. `GET /api/v1/health` reports that the process is up. Neither requires a token.

Coins not listed in `config/symbols.php` return 404. A time with no stored candle returns 404.

## Candle size

`interval` in `config/config.php` defaults to `1h`, which is what makes a time lookup useful. `1m`, `5m`, `15m`, `4h`, and `1d` also work. One-minute history for five years is a much larger download. After any interval change, delete `data/prices.sqlite` and run the backfill again.

You can also set `CRYPTO_INTERVAL` and `CRYPTO_YEARS` in `.env`.

## Tests

```bash
php tests/run.php
```
