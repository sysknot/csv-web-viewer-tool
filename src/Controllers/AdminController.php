<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Renderer;
use App\Repositories\AuditRepository;
use App\Repositories\DatasetRepository;
use App\Repositories\SettingsRepository;
use App\Security\Csrf;
use App\Services\AuthService;
use App\Services\DatabaseResetService;
use App\Support\Helpers;

final class AdminController
{
    public function __construct(private readonly Renderer $views, private readonly AuthService $auth, private readonly Csrf $csrf, private readonly DatasetRepository $dataset, private readonly SettingsRepository $settings, private readonly AuditRepository $audit, private readonly DatabaseResetService $databaseReset) {}

    public function dashboard(): void
    {
        $this->guard(); $this->page('Administración', 'admin/dashboard', ['dashboard' => $this->dataset->dashboard(), 'flash' => $this->pullFlash()]);
    }

    public function columns(): void
    {
        $this->guard(); $this->page('Configuración de columnas', 'admin/columns', ['columns' => $this->dataset->columns(includeHistorical: true), 'flash' => $this->pullFlash()]);
    }

    public function saveColumns(): void
    {
        $this->guard(); $this->verifyCsrf();
        $types = ['','text','integer','decimal','date','datetime','boolean']; $updates = [];
        foreach (($_POST['columns'] ?? []) as $id => $input) {
            if (!ctype_digit((string) $id) || !is_array($input)) continue;
            $type = (string) ($input['manual_type'] ?? '');
            if (!in_array($type, $types, true)) $type = '';
            $updates[$id] = [
                'alias' => trim((string) ($input['alias'] ?? '')),
                'manual_type' => $type,
                'visible_table' => isset($input['visible_table']) ? 1 : 0,
                'visible_detail' => isset($input['visible_detail']) ? 1 : 0,
                'display_order' => max(0, (int) ($input['display_order'] ?? 0)),
                'display_format' => trim((string) ($input['display_format'] ?? '')),
            ];
        }
        $this->dataset->updateColumns($updates); $this->audit->add($this->actor(), 'columns_updated', ['count' => count($updates)]); $this->flash('Configuración de columnas guardada.'); Helpers::redirect('/admin/columns');
    }

    public function data(): void
    {
        $this->guard(); $columns = $this->dataset->columns(tableOnly: true, includeHistorical: true);
        $sort = isset($_GET['sort']) && ctype_digit((string) $_GET['sort']) ? (int) $_GET['sort'] : null;
        $result = $this->dataset->paginated($columns, (int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? 25), $sort, (string) ($_GET['dir'] ?? 'asc'), trim((string) ($_GET['q'] ?? '')));
        $this->page('Datos', 'admin/data', ['columns' => $columns, 'result' => $result, 'sort' => $sort, 'direction' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc', 'search' => trim((string) ($_GET['q'] ?? '')), 'detailPrefix' => '/admin/record/']);
    }

    public function record(int $id): void
    {
        $this->guard(); $record = $this->dataset->record($id); if ($record === null) \App\Http\Response::notFound();
        $this->page('Detalle del registro', 'public/detail', ['record' => $record, 'columns' => $this->dataset->columns(detailOnly: true, includeHistorical: true), 'backUrl' => '/admin/data']);
    }

    public function history(): void
    {
        $this->guard(); $this->page('Historial de importaciones', 'admin/history', ['imports' => $this->dataset->imports()]);
    }

    public function settings(): void
    {
        $this->guard(); $this->page('Ajustes', 'admin/settings', ['showHistorical' => $this->settings->get('show_historical_columns', '0') === '1', 'pageSize' => $this->settings->get('default_page_size', '25'), 'keyColumns' => implode(', ', $this->settings->logicalKeyColumns()), 'flash' => $this->pullFlash()]);
    }

    public function saveSettings(): void
    {
        $this->guard(); $this->verifyCsrf(); $pageSize = (int) ($_POST['default_page_size'] ?? 25);
        if (!in_array($pageSize, [10, 25, 50, 100], true)) $pageSize = 25;
        $this->settings->set('show_historical_columns', isset($_POST['show_historical_columns']) ? '1' : '0'); $this->settings->set('default_page_size', (string) $pageSize);
        $this->audit->add($this->actor(), 'settings_updated'); $this->flash('Ajustes guardados.'); Helpers::redirect('/admin/settings');
    }

    public function changeReaderPassword(): void
    {
        $this->guard(); $this->verifyCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 12 || $password !== (string) ($_POST['password_confirmation'] ?? '')) {
            $this->flash('La contraseña no se ha cambiado: debe tener al menos 12 caracteres y coincidir en ambos campos.');
            Helpers::redirect('/admin/settings');
        }
        $this->auth->changeReaderPassword($password);
        $this->audit->add($this->actor(), 'reader_password_changed');
        $this->flash('Contraseña de acceso actualizada.');
        Helpers::redirect('/admin/settings');
    }

    public function resetDatabase(): void
    {
        $this->guard(); $this->verifyCsrf();
        if (trim((string) ($_POST['confirmation'] ?? '')) !== 'CONFIRMO') {
            $this->flash('No se ha eliminado nada: escribe CONFIRMO exactamente para confirmar la acción.');
            Helpers::redirect('/admin/settings');
        }
        $this->databaseReset->reset();
        $this->flash('Base de datos restablecida. Ya puedes importar un CSV nuevo.');
        Helpers::redirect('/admin/settings');
    }

    private function guard(): void
    {
        $this->auth->start('admin'); if (!$this->auth->isAdmin()) Helpers::redirect('/admin/login');
    }
    private function verifyCsrf(): void { if (!$this->csrf->validate($_POST['csrf'] ?? null)) \App\Http\Response::forbidden(); }
    private function actor(): string { return (string) ($_SESSION['username'] ?? 'admin'); }
    private function flash(string $message): void { $_SESSION['flash'] = $message; }
    private function pullFlash(): ?string { $message = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_string($message) ? $message : null; }
    /** @param array<string,mixed> $data */
    private function page(string $title, string $view, array $data): void { $csrf = $this->csrf->token(); $content = $this->views->partial($view, ['csrf' => $csrf] + $data); $this->views->render('layout', ['title' => $title, 'content' => $content, 'admin' => true, 'csrf' => $csrf]); }
}
