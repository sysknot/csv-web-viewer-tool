<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database\Connection;
use App\Database\Migrator;
use App\Repositories\SettingsRepository;
use App\Services\Csv\CsvAnalyzer;
use App\Services\Csv\TypeDetector;
use App\Services\Import\ImportService;
use App\Services\AuthService;
use App\Security\Session;
use App\Support\Logger;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { $failures++; fwrite(STDERR, "FALLO: {$message}\n"); } };
$temp = sys_get_temp_dir() . '/csv-viewer-test-' . bin2hex(random_bytes(5)); mkdir($temp, 0770, true);
try {
    $analyzer = new CsvAnalyzer(); $analysis = $analyzer->analyze(__DIR__ . '/Fixtures/initial.csv');
    $assert($analysis['errors'] === [], 'El CSV válido no debe tener errores.');
    $assert($analysis['types']['id'] === 'integer', 'Debe detectar enteros.');
    $assert($analysis['types']['activo'] === 'boolean', 'Debe detectar booleanos.');
    $assert($analysis['candidates']['id'] === true, 'Debe validar id único.');
    $assert(TypeDetector::isDate('2026-01-02'), 'Debe reconocer fechas ISO.');
    $connection = new Connection($temp . '/app.sqlite'); (new Migrator($connection->pdo()))->migrate();
    $settings = new SettingsRepository($connection->pdo()); $service = new ImportService($connection->pdo(), $analyzer, $settings, new Logger($temp . '/app.log'));
    $first = $service->import(__DIR__ . '/Fixtures/initial.csv', 'initial.csv', 1, ',', ['id'], false, 'test');
    $assert($first['inserted'] === 2 && $first['total'] === 2, 'La importación inicial debe insertar dos registros.');
    $second = $service->import(__DIR__ . '/Fixtures/update.csv', 'update.csv', 1, ',', ['id'], false, 'test');
    $assert($second['inserted'] === 1 && $second['updated'] === 1 && $second['removed'] === 1, 'La fotografía posterior debe insertar, actualizar e inactivar correctamente.');
    try { $service->import(__DIR__ . '/Fixtures/duplicate.csv', 'duplicate.csv', 1, ',', ['id'], false, 'test'); $assert(false, 'Debe rechazar claves duplicadas.'); } catch (RuntimeException) { $assert((int) $connection->pdo()->query('SELECT COUNT(*) FROM data_records WHERE active=1')->fetchColumn() === 2, 'Un fallo no debe alterar la fotografía anterior.'); }
    $service->import(__DIR__ . '/Fixtures/removed-column.csv', 'removed-column.csv', 1, ',', ['id'], false, 'test');
    $assert((int) $connection->pdo()->query("SELECT present_in_last_import FROM dataset_columns WHERE original_name = 'email'")->fetchColumn() === 0, 'Las columnas ausentes deben conservarse como históricas.');
    $auth = new AuthService(new Session(3600, false), 'admin', password_hash('admin-pass', PASSWORD_DEFAULT), password_hash('reader-pass', PASSWORD_DEFAULT));
    $auth->start('reader'); $assert(!$auth->loginReader('incorrecta'), 'No debe autenticar una contraseña pública inválida.'); $assert($auth->loginReader('reader-pass') && $auth->isReader(), 'Debe autenticar al lector.'); $auth->logout();
    $auth->start('admin'); $assert(!$auth->loginAdmin('admin', 'incorrecta'), 'No debe autenticar al administrador con contraseña inválida.'); $assert($auth->loginAdmin('admin', 'admin-pass') && $auth->isAdmin(), 'Debe mantener separado el ámbito administrativo.'); $auth->logout();
} finally {
    foreach (glob($temp . '/*') ?: [] as $file) @unlink($file); @rmdir($temp);
}
if ($failures > 0) exit(1); echo "OK: pruebas de importación completadas\n";
