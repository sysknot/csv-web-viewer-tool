<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Renderer;
use App\Repositories\DatasetRepository;
use App\Repositories\SettingsRepository;
use App\Security\Csrf;
use App\Services\AuthService;
use App\Support\Helpers;

final class PublicController
{
    public function __construct(private readonly Renderer $views, private readonly AuthService $auth, private readonly Csrf $csrf, private readonly DatasetRepository $dataset, private readonly SettingsRepository $settings) {}

    public function index(): void
    {
        $this->guard(); $columns = $this->dataset->columns(tableOnly: true, includeHistorical: $this->settings->get('show_historical_columns', '0') === '1');
        $sort = isset($_GET['sort']) && ctype_digit((string) $_GET['sort']) ? (int) $_GET['sort'] : null;
        $result = $this->dataset->paginated($columns, (int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? $this->settings->get('default_page_size', '25')), $sort, (string) ($_GET['dir'] ?? 'asc'), trim((string) ($_GET['q'] ?? '')));
        $this->page('Consulta de datos', 'public/table', ['columns' => $columns, 'result' => $result, 'sort' => $sort, 'direction' => ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc', 'search' => trim((string) ($_GET['q'] ?? ''))]);
    }

    public function detail(int $id): void
    {
        $this->guard(); $record = $this->dataset->record($id);
        if ($record === null) \App\Http\Response::notFound();
        $this->page('Detalle del registro', 'public/detail', ['record' => $record, 'columns' => $this->dataset->columns(detailOnly: true, includeHistorical: $this->settings->get('show_historical_columns', '0') === '1')]);
    }

    private function guard(): void
    {
        $this->auth->start('reader');
        if (!$this->auth->isReader()) Helpers::redirect('/login');
    }

    /** @param array<string,mixed> $data */
    private function page(string $title, string $view, array $data): void
    {
        $content = $this->views->partial($view, $data);
        $this->views->render('layout', ['title' => $title, 'content' => $content, 'admin' => false, 'csrf' => $this->csrf->token()]);
    }
}
