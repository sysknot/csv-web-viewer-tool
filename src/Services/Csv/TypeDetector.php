<?php
declare(strict_types=1);

namespace App\Services\Csv;

final class TypeDetector
{
    /** @param list<string|null> $values */
    public static function infer(array $values): string
    {
        $filled = array_values(array_filter($values, static fn (?string $value): bool => !self::isNull($value)));
        if ($filled === []) {
            return 'text';
        }
        if (self::all($filled, [self::class, 'isBoolean'])) return 'boolean';
        if (self::all($filled, [self::class, 'isInteger'])) return 'integer';
        if (self::all($filled, [self::class, 'isDecimal'])) return 'decimal';
        if (self::all($filled, [self::class, 'isDateTime'])) return 'datetime';
        if (self::all($filled, [self::class, 'isDate'])) return 'date';
        return 'text';
    }

    public static function isNull(?string $value): bool
    {
        return $value === null || trim($value) === '' || in_array(strtolower(trim($value)), ['null', 'n/a', 'na'], true);
    }

    public static function isBoolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', 'false', 'yes', 'no', 'sí', 'si', '0', '1'], true);
    }

    public static function isInteger(string $value): bool
    {
        return preg_match('/^[+-]?\d+$/', trim($value)) === 1;
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match('/^[+-]?(?:\d+[.,]\d+|\d{1,3}(?:\.\d{3})+,[0-9]+)$/', trim($value)) === 1;
    }

    public static function isDate(string $value): bool
    {
        return self::parseDate(trim($value), false) !== null;
    }

    public static function isDateTime(string $value): bool
    {
        return self::parseDate(trim($value), true) !== null;
    }

    public static function parseDate(string $value, bool $withTime): ?\DateTimeImmutable
    {
        $formats = $withTime
            ? ['!Y-m-d H:i:s', '!Y-m-d\\TH:i:sP', '!Y-m-d\\TH:i:s', '!d/m/Y H:i:s', '!d/m/Y H:i']
            : ['!Y-m-d', '!d/m/Y', '!d-m-Y'];
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }
        return null;
    }

    /** @param list<string> $values */
    private static function all(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) return false;
        }
        return true;
    }
}
