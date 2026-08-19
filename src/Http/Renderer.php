<?php
declare(strict_types=1);

namespace App\Http;

final class Renderer
{
    public function __construct(private readonly string $viewsPath) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): void
    {
        echo $this->partial($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vista no encontrada: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
