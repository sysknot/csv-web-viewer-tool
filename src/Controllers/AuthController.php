<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Renderer;
use App\Security\Csrf;
use App\Services\AuthService;
use App\Support\Logger;
use App\Repositories\AuditRepository;
use App\Support\Helpers;

final class AuthController
{
    public function __construct(
        private readonly Renderer $views,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Logger $logger,
        private readonly AuditRepository $audit,
    ) {}

    public function readerLogin(): void
    {
        $this->auth->start('reader');
        if ($this->auth->isReader()) {
            Helpers::redirect('/');
        }
        $this->views->render('auth/login', ['title' => 'Acceso a consultas', 'action' => '/login', 'admin' => false, 'csrf' => $this->csrf->token(), 'error' => null]);
    }

    public function readerLoginPost(): void
    {
        $this->auth->start('reader');
        if (!$this->csrf->validate($_POST['csrf'] ?? null)) {
            \App\Http\Response::forbidden();
        }
        $success = $this->auth->loginReader((string) ($_POST['password'] ?? ''));
        if (!$success) {
            $this->logger->error('reader_login_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            $this->views->render('auth/login', ['title' => 'Acceso a consultas', 'action' => '/login', 'admin' => false, 'csrf' => $this->csrf->token(), 'error' => 'Contraseña incorrecta.']);
            return;
        }
        $this->audit->add('reader', 'reader_login');
        Helpers::redirect('/');
    }

    public function readerLogout(): void
    {
        $this->auth->start('reader');
        if (!$this->csrf->validate($_POST['csrf'] ?? null)) {
            \App\Http\Response::forbidden();
        }
        $this->auth->logout();
        Helpers::redirect('/login');
    }

    public function adminLogin(): void
    {
        $this->auth->start('admin');
        if ($this->auth->isAdmin()) {
            Helpers::redirect('/admin');
        }
        $this->views->render('auth/login', ['title' => 'Administración', 'action' => '/admin/login', 'admin' => true, 'csrf' => $this->csrf->token(), 'error' => null]);
    }

    public function adminLoginPost(): void
    {
        $this->auth->start('admin');
        if (!$this->csrf->validate($_POST['csrf'] ?? null)) {
            \App\Http\Response::forbidden();
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        if (!$this->auth->loginAdmin($username, (string) ($_POST['password'] ?? ''))) {
            $this->logger->error('admin_login_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'username' => $username]);
            $this->views->render('auth/login', ['title' => 'Administración', 'action' => '/admin/login', 'admin' => true, 'csrf' => $this->csrf->token(), 'error' => 'Credenciales incorrectas.']);
            return;
        }
        $this->audit->add($username, 'admin_login');
        Helpers::redirect('/admin');
    }

    public function adminLogout(): void
    {
        $this->auth->start('admin');
        if (!$this->csrf->validate($_POST['csrf'] ?? null)) {
            \App\Http\Response::forbidden();
        }
        $actor = (string) ($_SESSION['username'] ?? 'admin');
        $this->audit->add($actor, 'admin_logout');
        $this->auth->logout();
        Helpers::redirect('/admin/login');
    }
}
