<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function forbidden(): never
    {
        http_response_code(403);
        echo 'Solicitud no autorizada.';
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        echo 'Página no encontrada.';
        exit;
    }
}
