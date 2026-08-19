<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DatasetRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function columns(bool $tableOnly = false, bool $detailOnly = false, bool $includeHistorical = false): array
    {
        $where = [];
        if ($tableOnly) $where[] = 'visible_table = 1';
        if ($detailOnly) $where[] = 'visible_detail = 1';
        if (!$includeHistorical) $where[] = 'present_in_last_import = 1';
        $sql = 'SELECT * FROM dataset_columns' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY display_order, id';
        return $this->db->query($sql)->fetchAll();
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $latest = $this->db->query('SELECT * FROM imports ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        return [
            'records' => (int) $this->db->query('SELECT COUNT(*) FROM data_records WHERE active = 1')->fetchColumn(),
            'columns' => (int) $this->db->query('SELECT COUNT(*) FROM dataset_columns')->fetchColumn(),
            'latest' => $latest,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function imports(): array
    {
        return $this->db->query('SELECT * FROM imports ORDER BY id DESC LIMIT 100')->fetchAll();
    }

    /** @return array{rows:list<array<string,mixed>>, total:int, page:int, perPage:int, pages:int} */
    public function paginated(array $columns, int $page, int $perPage, ?int $sortColumnId, string $direction, string $search): array
    {
        $page = max(1, $page); $perPage = min(max(10, $perPage), 100); $direction = $direction === 'asc' ? 'ASC' : 'DESC';
        $params = []; $where = 'r.active = 1';
        if ($search !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM record_values qv JOIN dataset_columns qc ON qc.id = qv.column_id WHERE qv.record_id = r.id AND qc.visible_table = 1 AND qv.raw_value LIKE :search ESCAPE '\\')";
            $params[':search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }
        $count = $this->db->prepare("SELECT COUNT(*) FROM data_records r WHERE {$where}"); $count->execute($params); $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage)); $page = min($page, $pages);
        $join = ''; $order = 'r.id DESC';
        if ($sortColumnId !== null) {
            $column = null; foreach ($columns as $candidate) if ((int) $candidate['id'] === $sortColumnId) $column = $candidate;
            if ($column !== null) {
                $join = ' LEFT JOIN record_values sv ON sv.record_id = r.id AND sv.column_id = :sort_column';
                $type = $column['manual_type'] ?: $column['detected_type'];
                $field = match ($type) { 'integer', 'decimal' => 'sv.normalized_number', 'date', 'datetime' => 'sv.normalized_datetime', 'boolean' => 'sv.normalized_boolean', default => 'sv.normalized_text COLLATE NOCASE' };
                $order = "{$field} {$direction}, r.id DESC"; $params[':sort_column'] = $sortColumnId;
            }
        }
        $query = $this->db->prepare("SELECT r.id FROM data_records r{$join} WHERE {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $query->bindValue($key, $value);
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT); $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT); $query->execute();
        $ids = array_map('intval', array_column($query->fetchAll(), 'id'));
        if ($ids === []) return compact('total', 'page', 'perPage', 'pages') + ['rows' => []];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $values = $this->db->prepare("SELECT record_id, column_id, raw_value FROM record_values WHERE record_id IN ({$marks})"); $values->execute($ids);
        $byRecord = array_fill_keys($ids, []);
        foreach ($values->fetchAll() as $value) $byRecord[(int) $value['record_id']][(int) $value['column_id']] = $value['raw_value'];
        $rows = []; foreach ($ids as $id) $rows[] = ['id' => $id, 'values' => $byRecord[$id]];
        return compact('rows', 'total', 'page', 'perPage', 'pages');
    }

    /** @return array<string,mixed>|null */
    public function record(int $id): ?array
    {
        $record = $this->db->prepare('SELECT id FROM data_records WHERE id = ? AND active = 1'); $record->execute([$id]);
        if ($record->fetch() === false) return null;
        $values = $this->db->prepare('SELECT column_id, raw_value FROM record_values WHERE record_id = ?'); $values->execute([$id]);
        return ['id' => $id, 'values' => array_column($values->fetchAll(), 'raw_value', 'column_id')];
    }

    /** @param array<string,array<string,mixed>> $updates */
    public function updateColumns(array $updates): void
    {
        $statement = $this->db->prepare('UPDATE dataset_columns SET alias = ?, manual_type = ?, visible_table = ?, visible_detail = ?, display_order = ?, display_format = ? WHERE id = ?');
        $this->db->beginTransaction();
        try {
            foreach ($updates as $id => $update) {
                $statement->execute([$update['alias'] ?: null, $update['manual_type'] ?: null, $update['visible_table'], $update['visible_detail'], $update['display_order'], $update['display_format'] ?: null, (int) $id]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) { $this->db->rollBack(); throw $exception; }
    }
}
