<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Uso: php bin/password-hash.php 'contraseña-segura'\n");
    exit(1);
}

echo password_hash($argv[1], PASSWORD_DEFAULT) . PHP_EOL;
