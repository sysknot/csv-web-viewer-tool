<?php
declare(strict_types=1);

namespace App\Security;

final class Session
{
    public function __construct(private readonly int $timeout, private readonly bool $secureCookies) {}

    public function start(string $scope): void
    {
        $name = $scope === 'admin' ? 'csv_admin_session' : 'csv_reader_session';
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $name) {
                throw new \LogicException('No se pueden mezclar ámbitos de sesión en la misma petición.');
            }
            return;
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $now = time();
        if (isset($_SESSION['last_activity']) && $now - (int) $_SESSION['last_activity'] > $this->timeout) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
