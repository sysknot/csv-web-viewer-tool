<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string, mixed> $details */
    public function add(string $actor, string $event, array $details = []): void
    {
        $statement = $this->db->prepare('INSERT INTO audit_log (created_at, actor, event, details) VALUES (?, ?, ?, ?)');
        $statement->execute([gmdate('c'), $actor, $event, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}
