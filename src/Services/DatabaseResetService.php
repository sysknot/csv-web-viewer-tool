<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Restablece la base de datos operativa, conservando únicamente el esquema y sus migraciones. */
final class DatabaseResetService
{
    public function __construct(private readonly PDO $db) {}

    public function reset(): void
    {
        $this->db->beginTransaction();
        try {
            // Se respetan schema_migrations y la infraestructura de SQLite; todo el estado de la aplicación se elimina.
            foreach (['record_values', 'data_records', 'import_errors', 'imports', 'dataset_columns', 'audit_log', 'app_settings'] as $table) {
                $this->db->exec("DELETE FROM {$table}");
            }
            $this->db->exec("DELETE FROM sqlite_sequence WHERE name IN ('dataset_columns', 'imports', 'import_errors', 'data_records', 'audit_log')");

            $now = gmdate('c');
            $statement = $this->db->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
            foreach ([
                'logical_key_columns' => '[]',
                'replace_mode' => '0',
                'show_historical_columns' => '0',
                'default_page_size' => '25',
            ] as $key => $value) {
                $statement->execute([$key, $value, $now]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }
}
