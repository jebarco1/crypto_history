<?php

declare(strict_types=1);

namespace CryptoHistory;

use InvalidArgumentException;
use Throwable;

final class Cli
{
    /** @param list<string> $argv
     *  @return array<string, string|true>
     */
    public static function args(array $argv): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                throw new InvalidArgumentException("Unexpected argument {$arg}");
            }
            $body = substr($arg, 2);
            if ($body === '') {
                throw new InvalidArgumentException('Empty option');
            }
            if (str_contains($body, '=')) {
                [$key, $value] = explode('=', $body, 2);
                $out[$key] = $value;
            } else {
                $out[$body] = true;
            }
        }

        return $out;
    }

    public static function fail(Throwable $e): never
    {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
