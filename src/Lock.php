<?php

declare(strict_types=1);

namespace CryptoHistory;

use RuntimeException;

final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the sync lock file');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another backfill or update is already running.');
        }
        $this->handle = $handle;
    }
}
