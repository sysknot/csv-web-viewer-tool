<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\ImportController;
use App\Controllers\PublicController;
use App\Database\Connection;
use App\Database\Migrator;
use App\Http\Renderer;
use App\Repositories\AuditRepository;
use App\Repositories\DatasetRepository;
use App\Repositories\SettingsRepository;
use App\Security\Csrf;
use App\Security\Session;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\Csv\CsvAnalyzer;
use App\Services\Import\ImportService;
use App\Support\Logger;
use App\Support\Helpers;

try {
    foreach (['uploads', 'logs', 'backups'] as $directory) {
        $path = $config['storage_path'] . '/' . $directory;
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) throw new RuntimeException('No se pudo preparar el almacenamiento.');
    }
    $connection = new Connection($config['db_path']); $db = $connection->pdo(); (new Migrator($db))->migrate();
    $logger = new Logger($config['storage_path'] . '/logs/app.log');
    $settings = new SettingsRepository($db); $audit = new AuditRepository($db); $dataset = new DatasetRepository($db);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $session = new Session($config['session_timeout'], $https); $csrf = new Csrf();
    $auth = new AuthService($session, (string) $config['admin_username'], (string) $config['admin_password_hash'], (string) $config['frontend_password_hash']);
    $renderer = new Renderer(APP_ROOT . '/src/Views');
    $authController = new AuthController($renderer, $auth, $csrf, $logger, $audit);
    $publicController = new PublicController($renderer, $auth, $csrf, $dataset, $settings);
    $adminController = new AdminController($renderer, $auth, $csrf, $dataset, $settings, $audit);
    $importController = new ImportController($renderer, $auth, $csrf, new ImportService($db, new CsvAnalyzer(), $settings, $logger), $settings, $audit, $config['storage_path'] . '/uploads', $config['upload_max_size']);
    $backupController = new BackupController($auth, $csrf, new BackupService($db, $config['storage_path'] . '/backups'));

    $path = Helpers::requestPath(); $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' && $path === '/login') $authController->readerLogin();
    elseif ($method === 'POST' && $path === '/login') $authController->readerLoginPost();
    elseif ($method === 'POST' && $path === '/logout') $authController->readerLogout();
    elseif ($method === 'GET' && $path === '/admin/login') $authController->adminLogin();
    elseif ($method === 'POST' && $path === '/admin/login') $authController->adminLoginPost();
    elseif ($method === 'POST' && $path === '/admin/logout') $authController->adminLogout();
    elseif ($method === 'GET' && $path === '/admin') $adminController->dashboard();
    elseif ($method === 'GET' && $path === '/admin/import') $importController->form();
    elseif ($method === 'POST' && $path === '/admin/import/preview') $importController->preview();
    elseif ($method === 'POST' && $path === '/admin/import/confirm') $importController->confirm();
    elseif ($method === 'GET' && $path === '/admin/columns') $adminController->columns();
    elseif ($method === 'POST' && $path === '/admin/columns') $adminController->saveColumns();
    elseif ($method === 'GET' && $path === '/admin/data') $adminController->data();
    elseif ($method === 'GET' && preg_match('#^/admin/record/(\d+)$#', $path, $matches)) $adminController->record((int) $matches[1]);
    elseif ($method === 'GET' && $path === '/admin/history') $adminController->history();
    elseif ($method === 'GET' && $path === '/admin/settings') $adminController->settings();
    elseif ($method === 'POST' && $path === '/admin/settings') $adminController->saveSettings();
    elseif ($method === 'POST' && $path === '/admin/backups') $backupController->download();
    elseif ($method === 'GET' && preg_match('#^/record/(\d+)$#', $path, $matches)) $publicController->detail((int) $matches[1]);
    elseif ($method === 'GET' && $path === '/') $publicController->index();
    else \App\Http\Response::notFound();
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('[csv-viewer] ' . $exception->getMessage());
    if (($config['env'] ?? 'production') === 'development') throw $exception;
    echo 'Se ha producido un error interno. Revisa los registros de la aplicación.';
}
