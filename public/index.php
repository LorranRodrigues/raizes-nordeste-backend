<?php

declare(strict_types=1);

/**
 * Front Controller — ponto único de entrada da API.
 *
 * Toda requisição passa por aqui (via .htaccess). Responsável por:
 *  - registrar o autoload e as configurações;
 *  - tratar CORS e preflight;
 *  - capturar exceções não previstas e devolver JSON consistente.
 */

use App\Core\Response;
use App\Core\Router;

require __DIR__ . '/../bootstrap.php';

// Cabeçalhos padrão da API (JSON + CORS básico).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

// Requisição preflight do CORS não precisa chegar às rotas.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router = new Router();
    require __DIR__ . '/../routes/api.php'; // registra as rotas no $router
    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'
    );
} catch (\App\Core\HttpException $e) {
    Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $e->getDetails());
} catch (\Throwable $e) {
    // Erro inesperado: não vaza detalhes internos em produção.
    $details = (require __DIR__ . '/../config/config.php')['app']['debug']
        ? [['field' => 'exception', 'issue' => $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()]]
        : null;
    Response::error('ERRO_INTERNO', 'Erro interno no servidor.', 500, $details);
}
