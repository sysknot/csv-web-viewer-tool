<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $db) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->db->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
        $statement->execute([$key, $value, gmdate('c')]);
    }

    /** @return list<string> */
    public function logicalKeyColumns(): array
    {
        $value = json_decode($this->get('logical_key_columns', '[]') ?? '[]', true);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
