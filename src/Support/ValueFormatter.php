<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\Csv\TypeDetector;

final class ValueFormatter
{
    /** @param array<string,mixed> $column */
    public static function format(?string $value, array $column): string
    {
        if ($value === null || TypeDetector::isNull($value)) return '—';
        $type = $column['manual_type'] ?: $column['detected_type'];
        if ($type === 'boolean') return in_array(strtolower(trim($value)), ['true', 'yes', 'sí', 'si', '1'], true) ? 'Sí' : 'No';
        if ($type === 'integer') return number_format((int) $value, 0, ',', '.');
        if ($type === 'decimal') return number_format((float) str_replace(',', '.', $value), 2, ',', '.');
        if ($type === 'date' || $type === 'datetime') {
            $date = TypeDetector::parseDate(trim($value), $type === 'datetime');
            if ($date) return $date->format($column['display_format'] ?: ($type === 'date' ? 'd/m/Y' : 'd/m/Y H:i'));
        }
        return $value;
    }

    /** Devuelve una URL navegable únicamente para direcciones web HTTP(S) válidas. */
    public static function webUrl(?string $value): ?string
    {
        if ($value === null) return null;
        $url = trim($value);
        if ($url === '' || preg_match('/\s/', $url)) return null;
        if (str_starts_with(strtolower($url), 'www.')) $url = 'https://' . $url;
        if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
