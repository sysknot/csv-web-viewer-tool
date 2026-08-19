<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Security\Csrf;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Support\Helpers;

final class BackupController
{
    public function __construct(private readonly AuthService $auth, private readonly Csrf $csrf, private readonly BackupService $backups) {}
    public function download(): never
    {
        $this->auth->start('admin'); if (!$this->auth->isAdmin()) Helpers::redirect('/admin/login');
        if (!$this->csrf->validate($_POST['csrf'] ?? null)) Response::forbidden();
        $this->backups->download();
    }
}
