<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Renderer;
use App\Repositories\AuditRepository;
use App\Repositories\SettingsRepository;
use App\Security\Csrf;
use App\Services\AuthService;
use App\Services\Import\ImportService;
use App\Support\Helpers;

final class ImportController
{
    public function __construct(private readonly Renderer $views, private readonly AuthService $auth, private readonly Csrf $csrf, private readonly ImportService $importer, private readonly SettingsRepository $settings, private readonly AuditRepository $audit, private readonly string $uploadPath, private readonly int $maxSize) {}

    public function form(): void { $this->guard(); $this->page('Importar CSV', 'admin/import-form', ['maxSize' => $this->maxSize, 'flash' => $this->pullFlash()]); }
    public function preview(): void
    {
        $this->guard(); $this->verifyCsrf();
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $this->flash('No se pudo subir el archivo.'); Helpers::redirect('/admin/import'); }
        if ((int) $file['size'] < 1 || (int) $file['size'] > $this->maxSize) { $this->flash('El archivo supera el límite permitido o está vacío.'); Helpers::redirect('/admin/import'); }
        $name = basename((string) $file['name']);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') { $this->flash('Solo se admiten archivos .csv.'); Helpers::redirect('/admin/import'); }
        if (!is_dir($this->uploadPath)) mkdir($this->uploadPath, 0770, true);
        $token = bin2hex(random_bytes(20)); $path = $this->uploadPath . '/' . $token . '.csv';
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) throw new \RuntimeException('No se pudo guardar temporalmente el archivo.');
        $prefix = file_get_contents($path, false, null, 0, 4096);
        if ($prefix === false || str_contains($prefix, "\0")) { @unlink($path); $this->flash('El archivo no parece un CSV de texto válido.'); Helpers::redirect('/admin/import'); }
        $analysis = $this->importer->analyze($path, ($_POST['delimiter'] ?? '') ?: null);
        $pending = ['token' => $token, 'path' => $path, 'filename' => $name, 'size' => (int) $file['size'], 'analysis' => $analysis];
        if ($analysis['errors'] === []) $_SESSION['pending_import'] = $pending; else @unlink($path);
        $this->page('Revisar importación', 'admin/import-preview', ['pending' => $pending, 'keyColumns' => $this->settings->logicalKeyColumns(), 'replaceMode' => $this->settings->get('replace_mode', '0') === '1']);
    }
    public function confirm(): void
    {
        $this->guard(); $this->verifyCsrf(); $pending = $_SESSION['pending_import'] ?? null;
        if (!is_array($pending) || !is_file((string) ($pending['path'] ?? ''))) { $this->flash('La vista previa ha caducado; sube el archivo de nuevo.'); Helpers::redirect('/admin/import'); }
        $headers = $pending['analysis']['headers'] ?? []; $keys = array_values(array_unique(array_filter((array) ($_POST['key_columns'] ?? []), static fn ($key): bool => is_string($key) && in_array($key, $headers, true))));
        $replace = isset($_POST['replace_mode']);
        try {
            $result = $this->importer->import($pending['path'], $pending['filename'], (int) $pending['size'], $pending['analysis']['delimiter'], $keys, $replace, $this->actor());
            $this->audit->add($this->actor(), 'import_confirmed', $result); $this->flash("Importación completada: {$result['total']} filas, {$result['inserted']} nuevas, {$result['updated']} actualizadas.");
        } catch (\Throwable $exception) { $this->flash('Importación rechazada: ' . $exception->getMessage()); }
        @unlink($pending['path']); unset($_SESSION['pending_import']); Helpers::redirect('/admin');
    }
    private function guard(): void { $this->auth->start('admin'); if (!$this->auth->isAdmin()) Helpers::redirect('/admin/login'); }
    private function verifyCsrf(): void { if (!$this->csrf->validate($_POST['csrf'] ?? null)) \App\Http\Response::forbidden(); }
    private function actor(): string { return (string) ($_SESSION['username'] ?? 'admin'); }
    private function flash(string $message): void { $_SESSION['flash'] = $message; }
    private function pullFlash(): ?string { $message = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_string($message) ? $message : null; }
    /** @param array<string,mixed> $data */
    private function page(string $title, string $view, array $data): void { $csrf = $this->csrf->token(); $content = $this->views->partial($view, ['csrf' => $csrf] + $data); $this->views->render('layout', ['title' => $title, 'content' => $content, 'admin' => true, 'csrf' => $csrf]); }
}
