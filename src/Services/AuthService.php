<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Session;

final class AuthService
{
    public function __construct(
        private readonly Session $session,
        private readonly string $adminUsername,
        private readonly string $adminHash,
        private readonly string $readerHash,
    ) {}

    public function start(string $scope): void
    {
        $this->session->start($scope);
    }

    public function loginReader(string $password): bool
    {
        if ($this->readerHash === '' || !password_verify($password, $this->readerHash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['role'] = 'reader';
        return true;
    }

    public function loginAdmin(string $username, string $password): bool
    {
        if ($this->adminHash === '' || !hash_equals($this->adminUsername, $username) || !password_verify($password, $this->adminHash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = $username;
        return true;
    }

    public function isReader(): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true && ($_SESSION['role'] ?? null) === 'reader';
    }

    public function isAdmin(): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true && ($_SESSION['role'] ?? null) === 'admin';
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
