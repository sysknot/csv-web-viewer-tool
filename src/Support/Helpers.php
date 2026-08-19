<?php
declare(strict_types=1);

namespace App\Support;

final class Helpers
{
    public static function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    public static function requestPath(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }
}
