<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $db) {}

    public function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        $version = (int) $this->db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
        if ($version >= 1) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->exec(<<<'SQL'
CREATE TABLE app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE dataset_columns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_name TEXT NOT NULL UNIQUE,
    alias TEXT NULL,
    detected_type TEXT NOT NULL DEFAULT 'text',
    manual_type TEXT NULL,
    display_format TEXT NULL,
    visible_table INTEGER NOT NULL DEFAULT 1 CHECK (visible_table IN (0, 1)),
    visible_detail INTEGER NOT NULL DEFAULT 1 CHECK (visible_detail IN (0, 1)),
    display_order INTEGER NOT NULL DEFAULT 0,
    present_in_last_import INTEGER NOT NULL DEFAULT 1 CHECK (present_in_last_import IN (0, 1)),
    first_detected_at TEXT NOT NULL,
    last_detected_at TEXT NOT NULL
);

CREATE TABLE imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    completed_at TEXT NULL,
    filename TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    delimiter TEXT NOT NULL,
    total_rows INTEGER NOT NULL DEFAULT 0,
    inserted_rows INTEGER NOT NULL DEFAULT 0,
    updated_rows INTEGER NOT NULL DEFAULT 0,
    removed_rows INTEGER NOT NULL DEFAULT 0,
    error_rows INTEGER NOT NULL DEFAULT 0,
    duration_ms INTEGER NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
    summary TEXT NULL,
    initiated_by TEXT NOT NULL
);

CREATE TABLE import_errors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_id INTEGER NOT NULL REFERENCES imports(id) ON DELETE CASCADE,
    row_number INTEGER NULL,
    severity TEXT NOT NULL CHECK (severity IN ('warning', 'error')),
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE data_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    logical_key TEXT NOT NULL UNIQUE,
    logical_key_json TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_seen_import_id INTEGER NULL REFERENCES imports(id)
);

CREATE TABLE record_values (
    record_id INTEGER NOT NULL REFERENCES data_records(id) ON DELETE CASCADE,
    column_id INTEGER NOT NULL REFERENCES dataset_columns(id),
    raw_value TEXT NULL,
    normalized_text TEXT NULL,
    normalized_number REAL NULL,
    normalized_datetime TEXT NULL,
    normalized_boolean INTEGER NULL,
    last_seen_import_id INTEGER NULL REFERENCES imports(id),
    PRIMARY KEY (record_id, column_id)
);

CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    actor TEXT NOT NULL,
    event TEXT NOT NULL,
    details TEXT NULL
);

CREATE INDEX idx_records_active_seen ON data_records(active, last_seen_import_id);
CREATE INDEX idx_values_column_text ON record_values(column_id, normalized_text);
CREATE INDEX idx_values_column_number ON record_values(column_id, normalized_number);
CREATE INDEX idx_values_column_datetime ON record_values(column_id, normalized_datetime);
CREATE INDEX idx_values_column_boolean ON record_values(column_id, normalized_boolean);
CREATE INDEX idx_imports_created_at ON imports(created_at DESC);
SQL);
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
            $this->db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (1, ?)')->execute([$now]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}
