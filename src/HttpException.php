<?php

declare(strict_types=1);

namespace CryptoHistory;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
