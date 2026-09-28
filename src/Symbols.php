<?php

declare(strict_types=1);

namespace CryptoHistory;

use InvalidArgumentException;

final class Symbols
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->config['symbols'];
    }

    public function has(string $symbol): bool
    {
        return in_array(strtoupper(trim($symbol)), $this->all(), true);
    }

    public function pair(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        $overrides = $this->config['pair_overrides'] ?? [];
        if (isset($overrides[$symbol]) && is_string($overrides[$symbol]) && $overrides[$symbol] !== '') {
            return strtoupper($overrides[$symbol]);
        }

        return $symbol . strtoupper((string) $this->config['quote']);
    }

    /**
     * @return list<string>
     */
    public function select(?string $requested): array
    {
        if ($requested === null || trim($requested) === '') {
            return $this->all();
        }

        $want = [];
        foreach (explode(',', $requested) as $part) {
            $symbol = strtoupper(trim($part));
            if ($symbol === '') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9]{2,15}$/', $symbol)) {
                throw new InvalidArgumentException("Invalid symbol: {$symbol}");
            }
            $want[] = $symbol;
        }
        $want = array_values(array_unique($want));
        if ($want === []) {
            throw new InvalidArgumentException('No symbols given');
        }

        $unknown = array_values(array_diff($want, $this->all()));
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown symbol(s): ' . implode(', ', $unknown) . '. Add them to config/symbols.php, then backfill.'
            );
        }

        return $want;
    }
}
