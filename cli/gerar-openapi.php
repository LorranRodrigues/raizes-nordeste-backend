<?php

declare(strict_types=1);

/**
 * isso é pra gerar o openapi atraves da cli do php
 * Uso: php cli/gerar-openapi.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Support\OpenApi;

$destino = __DIR__ . '/../docs/openapi.json';
$json = json_encode(OpenApi::spec(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($destino, $json);

echo "OpenAPI exportado para docs/openapi.json (" . strlen($json) . " bytes).\n";
