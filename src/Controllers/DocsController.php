<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Support\OpenApi;

/**
 * Serve a documentação da API:
 *  - GET /openapi.json → especificação OpenAPI 3.0;
 *  - GET /docs         → Swagger UI (renderiza a especificação acima).
 */
final class DocsController
{
    public function spec(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(OpenApi::spec(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public function ui(Request $request): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        $base = rtrim($config['app']['base_path'], '/');
        $specUrl = $base . '/openapi.json';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>API Raízes do Nordeste — Documentação</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.onload = () => {
      window.ui = SwaggerUIBundle({
        url: '{$specUrl}',
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout'
      });
    };
  </script>
</body>
</html>
HTML;
        exit;
    }
}
