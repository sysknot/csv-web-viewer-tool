<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Repositories\SettingsRepository;
use App\Services\Csv\CsvAnalyzer;
use App\Services\Csv\ValueNormalizer;
use App\Support\Logger;
use PDO;

final class ImportService
{
    public function __construct(private readonly PDO $db, private readonly CsvAnalyzer $analyzer, private readonly SettingsRepository $settings, private readonly Logger $logger) {}

    /** @return array<string, mixed> */
    public function analyze(string $path, ?string $delimiter = null): array
    {
        return $this->analyzer->analyze($path, $delimiter);
    }

    /** @param list<string> $keyColumns @return array<string, int> */
    public function import(string $path, string $filename, int $fileSize, string $delimiter, array $keyColumns, bool $replaceMode, string $actor): array
    {
        $analysis = $this->analyzer->analyze($path, $delimiter);
        if ($analysis['errors'] !== []) throw new \RuntimeException(implode(' ', $analysis['errors']));
        $headers = $analysis['headers'];
        if (!$replaceMode && ($keyColumns === [] || array_diff($keyColumns, $headers) !== [])) {
            throw new \RuntimeException('La clave lógica configurada no existe o no es válida para este CSV.');
        }
        $now = gmdate('c');
        $statement = $this->db->prepare('INSERT INTO imports (created_at, filename, file_size, delimiter, status, initiated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$now, $filename, $fileSize, $delimiter, 'running', $actor]);
        $importId = (int) $this->db->lastInsertId();
        $started = microtime(true);
        try {
            $this->db->beginTransaction();
            $this->db->exec('CREATE TEMP TABLE staging_records (logical_key TEXT PRIMARY KEY, logical_key_json TEXT NOT NULL, source_hash TEXT NOT NULL, values_json TEXT NOT NULL, row_number INTEGER NOT NULL)');
            $insertStage = $this->db->prepare('INSERT INTO staging_records (logical_key, logical_key_json, source_hash, values_json, row_number) VALUES (?, ?, ?, ?, ?)');
            $handle = fopen($path, 'rb');
            if ($handle === false) throw new \RuntimeException('No se pudo abrir el CSV para importar.');
            fgetcsv($handle, 0, $delimiter, '"', '\\');
            $rowNumber = 0;
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($row === [null]) continue;
                $rowNumber++;
                if (count($row) !== count($headers)) throw new \RuntimeException("Fila {$rowNumber}: número de campos inválido.");
                $values = array_combine($headers, array_map(static fn ($value): ?string => $value === null ? null : (string) $value, $row));
                if ($values === false) throw new \RuntimeException("Fila {$rowNumber}: no se pudieron asociar las columnas.");
                [$key, $keyJson] = $this->logicalKey($values, $keyColumns, $replaceMode, $rowNumber);
                $canonical = $values; ksort($canonical, SORT_STRING);
                $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                try { $insertStage->execute([$key, $keyJson, $hash, json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rowNumber]); }
                catch (\PDOException $exception) { throw new \RuntimeException("Fila {$rowNumber}: clave lógica duplicada.", previous: $exception); }
            }
            fclose($handle);
            $columnMap = $this->syncColumns($headers, $analysis['types']);
            $columns = $this->loadColumns();
            $existing = $this->db->prepare('SELECT id, source_hash, active FROM data_records WHERE logical_key = ?');
            $addRecord = $this->db->prepare('INSERT INTO data_records (logical_key, logical_key_json, source_hash, active, created_at, updated_at, last_seen_import_id) VALUES (?, ?, ?, 1, ?, ?, ?)');
            $updateRecord = $this->db->prepare('UPDATE data_records SET logical_key_json = ?, source_hash = ?, active = 1, updated_at = ?, last_seen_import_id = ? WHERE id = ?');
            $upsertValue = $this->db->prepare('INSERT INTO record_values (record_id, column_id, raw_value, normalized_text, normalized_number, normalized_datetime, normalized_boolean, last_seen_import_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(record_id, column_id) DO UPDATE SET raw_value=excluded.raw_value, normalized_text=excluded.normalized_text, normalized_number=excluded.normalized_number, normalized_datetime=excluded.normalized_datetime, normalized_boolean=excluded.normalized_boolean, last_seen_import_id=excluded.last_seen_import_id');
            $staged = $this->db->query('SELECT logical_key, logical_key_json, source_hash, values_json FROM staging_records ORDER BY row_number');
            $inserted = 0; $updated = 0; $total = 0;
            while ($stage = $staged->fetch()) {
                $total++;
                $existing->execute([$stage['logical_key']]);
                $record = $existing->fetch();
                if ($record === false) {
                    $addRecord->execute([$stage['logical_key'], $stage['logical_key_json'], $stage['source_hash'], $now, $now, $importId]);
                    $recordId = (int) $this->db->lastInsertId(); $inserted++;
                } else {
                    $recordId = (int) $record['id'];
                    if ($record['source_hash'] !== $stage['source_hash'] || (int) $record['active'] !== 1) $updated++;
                    $updateRecord->execute([$stage['logical_key_json'], $stage['source_hash'], $now, $importId, $recordId]);
                }
                $values = json_decode($stage['values_json'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($headers as $header) {
                    $column = $columns[$header];
                    $type = $column['manual_type'] ?: $column['detected_type'];
                    $normal = ValueNormalizer::normalize($values[$header], $type);
                    $upsertValue->execute([$recordId, $columnMap[$header], $normal['raw'], $normal['text'], $normal['number'], $normal['datetime'], $normal['boolean'], $importId]);
                }
            }
            $deactivate = $this->db->prepare('UPDATE data_records SET active = 0, updated_at = ? WHERE active = 1 AND (last_seen_import_id IS NULL OR last_seen_import_id != ?)');
            $deactivate->execute([$now, $importId]);
            $removed = $deactivate->rowCount();
            $duration = (int) round((microtime(true) - $started) * 1000);
            $finish = $this->db->prepare('UPDATE imports SET completed_at = ?, total_rows = ?, inserted_rows = ?, updated_rows = ?, removed_rows = ?, duration_ms = ?, status = ?, summary = ? WHERE id = ?');
            $finish->execute([gmdate('c'), $total, $inserted, $updated, $removed, $duration, 'completed', 'Importación completada.', $importId]);
            $this->settings->set('logical_key_columns', json_encode($keyColumns, JSON_THROW_ON_ERROR));
            $this->settings->set('replace_mode', $replaceMode ? '1' : '0');
            $this->db->commit();
            $this->logger->info('import_completed', ['import_id' => $importId, 'rows' => $total, 'actor' => $actor]);
            return compact('importId', 'total', 'inserted', 'updated', 'removed', 'duration');
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->db->prepare('UPDATE imports SET completed_at = ?, duration_ms = ?, status = ?, summary = ? WHERE id = ?')->execute([gmdate('c'), $duration, 'failed', $exception->getMessage(), $importId]);
            $this->logger->error('import_failed', ['import_id' => $importId, 'actor' => $actor, 'message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    /** @param array<string, ?string> $values @param list<string> $keyColumns @return array{string,string} */
    private function logicalKey(array $values, array $keyColumns, bool $replaceMode, int $rowNumber): array
    {
        if ($replaceMode) return ['replace:' . $rowNumber, json_encode(['row' => $rowNumber], JSON_THROW_ON_ERROR)];
        $parts = [];
        foreach ($keyColumns as $column) {
            $value = trim((string) ($values[$column] ?? ''));
            if ($value === '' || in_array(strtolower($value), ['null', 'n/a', 'na'], true)) throw new \RuntimeException("Fila {$rowNumber}: la clave lógica contiene un valor vacío.");
            $parts[$column] = $value;
        }
        $json = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [hash('sha256', $json), $json];
    }

    /** @param list<string> $headers @param array<string,string> $types @return array<string,int> */
    private function syncColumns(array $headers, array $types): array
    {
        $this->db->exec('UPDATE dataset_columns SET present_in_last_import = 0');
        $max = (int) $this->db->query('SELECT COALESCE(MAX(display_order), 0) FROM dataset_columns')->fetchColumn();
        $find = $this->db->prepare('SELECT id FROM dataset_columns WHERE original_name = ?');
        $insert = $this->db->prepare('INSERT INTO dataset_columns (original_name, detected_type, display_order, present_in_last_import, first_detected_at, last_detected_at) VALUES (?, ?, ?, 1, ?, ?)');
        $update = $this->db->prepare('UPDATE dataset_columns SET detected_type = ?, present_in_last_import = 1, last_detected_at = ? WHERE id = ?');
        $map = []; $now = gmdate('c');
        foreach ($headers as $index => $header) {
            $find->execute([$header]); $id = $find->fetchColumn();
            if ($id === false) { $insert->execute([$header, $types[$header] ?? 'text', ++$max, $now, $now]); $id = $this->db->lastInsertId(); }
            else $update->execute([$types[$header] ?? 'text', $now, $id]);
            $map[$header] = (int) $id;
        }
        return $map;
    }

    /** @return array<string,array<string,mixed>> */
    private function loadColumns(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT * FROM dataset_columns') as $column) $out[$column['original_name']] = $column;
        return $out;
    }
}
