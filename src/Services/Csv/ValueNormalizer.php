<?php
declare(strict_types=1);

namespace App\Services\Csv;

final class ValueNormalizer
{
    /** @return array{raw: ?string, text: ?string, number: ?float, datetime: ?string, boolean: ?int} */
    public static function normalize(?string $raw, string $type): array
    {
        if (TypeDetector::isNull($raw)) {
            return ['raw' => $raw, 'text' => null, 'number' => null, 'datetime' => null, 'boolean' => null];
        }
        $value = trim((string) $raw);
        $normalized = ['raw' => $raw, 'text' => $value, 'number' => null, 'datetime' => null, 'boolean' => null];
        if ($type === 'integer' || $type === 'decimal') {
            $number = self::number($value);
            if (is_numeric($number)) $normalized['number'] = (float) $number;
        } elseif ($type === 'date' || $type === 'datetime') {
            $date = TypeDetector::parseDate($value, $type === 'datetime');
            if ($date !== null) $normalized['datetime'] = $date->format('c');
        } elseif ($type === 'boolean') {
            $normalized['boolean'] = in_array(strtolower($value), ['true', 'yes', 'sí', 'si', '1'], true) ? 1 : 0;
        }
        return $normalized;
    }

    private static function number(string $value): string
    {
        $comma = strrpos($value, ','); $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            return $comma > $dot ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        }
        return str_replace(',', '.', $value);
    }
}
