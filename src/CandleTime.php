<?php

declare(strict_types=1);

namespace CryptoHistory;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

final class CandleTime
{
    public static function intervalMs(string $interval): int
    {
        return match ($interval) {
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '1h' => 3_600_000,
            '4h' => 14_400_000,
            '1d' => 86_400_000,
            default => throw new InvalidArgumentException(
                "Unsupported interval {$interval}. Use 1m, 5m, 15m, 1h, 4h, or 1d."
            ),
        };
    }

    public static function floorMs(int $timestampMs, int $intervalMs): int
    {
        if ($intervalMs < 1) {
            throw new InvalidArgumentException('Invalid interval');
        }
        if ($timestampMs < 0) {
            throw new InvalidArgumentException('Timestamp must be zero or greater');
        }

        return intdiv($timestampMs, $intervalMs) * $intervalMs;
    }

    public static function toIso(int $timestampMs): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', intdiv($timestampMs, 1000));
    }

    public static function parseStart(string $value): int
    {
        return self::parse($value, false);
    }

    public static function parseEnd(string $value): int
    {
        return self::parse($value, true);
    }

    public static function parseClock(string $date, string $time): int
    {
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            $time .= ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
            throw new InvalidArgumentException('time must be HH:MM or HH:MM:SS in UTC');
        }

        return self::parse(trim($date) . ' ' . $time, false);
    }

    private static function parse(string $value, bool $endOfDay): int
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('A date or time is required');
        }

        $tz = new DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
            self::guard($dt, $value);
            if ($endOfDay) {
                $dt = $dt->setTime(23, 59, 59);

                return ((int) $dt->format('U')) * 1000 + 999;
            }

            return ((int) $dt->format('U')) * 1000;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        try {
            $dt = new DateTimeImmutable($value, $tz);
        } catch (Exception) {
            throw new InvalidArgumentException("Invalid date or time: {$value}");
        }

        $ms = ((int) $dt->format('U')) * 1000 + (int) $dt->format('v');
        if ($ms < 0) {
            throw new InvalidArgumentException("Invalid date or time: {$value}");
        }

        return $ms;
    }

    private static function guard(DateTimeImmutable|false $dt, string $value): void
    {
        $errors = DateTimeImmutable::getLastErrors();
        $failed = $dt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));
        if ($failed) {
            throw new InvalidArgumentException("Invalid date or time: {$value}");
        }
    }
}
