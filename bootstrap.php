<?php

declare(strict_types=1);

/**
 * Bootstrap da aplicação.
 *
 * Carrega o autoloader simples (PSR-4 sobre o namespace App\) e define
 * o tratamento de erros como exceções, para que tudo seja capturado
 * no front controller e devolvido em JSON.
 */

require __DIR__ . '/autoload.php';

// Carrega variáveis de um arquivo .env (se existir), tornando a configuração
// reproduzível sem alterar código. Linhas no formato CHAVE=valor; # = comentário.
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        $valor = trim($valor, " \t\"'");
        if (getenv($chave) === false) {
            putenv("{$chave}={$valor}");
        }
    }
}

$config = require __DIR__ . '/config/config.php';

date_default_timezone_set($config['app']['timezone']);

// Em desenvolvimento mostramos erros; em produção, silenciamos a saída
// (o front controller converte exceções em JSON de forma controlada).
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Transforma warnings/notices do PHP em exceções tratáveis.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
