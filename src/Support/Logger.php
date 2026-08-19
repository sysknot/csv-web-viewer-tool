<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public function __construct(private readonly string $path) {}

    /** @param array<string, scalar|null> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $event, array $context): void
    {
        $line = json_encode(['time' => gmdate('c'), 'level' => $level, 'event' => $event, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            error_log($line . PHP_EOL, 3, $this->path);
        }
    }
}
