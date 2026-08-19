<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class BackupService
{
    public function __construct(private readonly PDO $db, private readonly string $backupPath) {}

    public function download(): never
    {
        if (!is_dir($this->backupPath) && !mkdir($this->backupPath, 0770, true) && !is_dir($this->backupPath)) throw new \RuntimeException('No se pudo preparar el directorio de backup.');
        $file = $this->backupPath . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        // La ruta se genera en el servidor y se cita con PDO; nunca procede de la petición.
        $this->db->exec('VACUUM INTO ' . $this->db->quote($file));
        header('Content-Type: application/vnd.sqlite3'); header('Content-Disposition: attachment; filename="csv-viewer-backup-' . gmdate('Ymd-His') . '.sqlite"'); header('Content-Length: ' . filesize($file)); header('X-Content-Type-Options: nosniff');
        readfile($file); @unlink($file); exit;
    }
}
