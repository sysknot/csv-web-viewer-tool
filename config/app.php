<?php
declare(strict_types=1);

$env = static function (string $name, ?string $default = null): ?string {
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
};

return [
    'env' => $env('APP_ENV', 'production'),
    'secret' => $env('APP_SECRET', ''),
    'url' => rtrim((string) $env('APP_URL', ''), '/'),
    'timezone' => $env('APP_TIMEZONE', 'UTC'),
    'db_path' => $env('DB_PATH', APP_ROOT . '/storage/database/app.sqlite'),
    'upload_max_size' => max(1, (int) $env('UPLOAD_MAX_SIZE', '52428800')),
    'session_timeout' => max(300, (int) $env('SESSION_TIMEOUT', '3600')),
    'admin_username' => $env('ADMIN_USERNAME', 'admin'),
    'admin_password_hash' => $env('ADMIN_PASSWORD_HASH', ''),
    'frontend_password_hash' => $env('FRONTEND_PASSWORD_HASH', ''),
    'storage_path' => APP_ROOT . '/storage',
];
