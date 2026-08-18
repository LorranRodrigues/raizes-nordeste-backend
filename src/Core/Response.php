<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Padroniza todas as respostas da API em JSON.
 *
 * - Sucesso: envelope { success, data, meta } (meta carrega paginação).
 * - Erro:    formato padronizado do roteiro
 *            { success:false, error, message, details[], timestamp, path, requestId }.
 */
final class Response
{
    /**
     * Resposta de sucesso.
     *
     * @param mixed $data   Conteúdo retornado.
     * @param int   $status Código HTTP (200, 201, ...).
     * @param array $meta   Metadados opcionais (paginação, totais etc.).
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): void
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::send($payload, $status);
    }

    /**
     * Resposta de erro no formato padronizado.
     *
     * @param string     $code    Código textual do erro (ex.: VALIDACAO, NAO_ENCONTRADO).
     * @param string     $message Mensagem legível.
     * @param int        $status  Código HTTP do erro.
     * @param array|null $details Lista de problemas: [{ field, issue }].
     */
    public static function error(string $code, string $message, int $status = 400, ?array $details = null): void
    {
        self::send([
            'success' => false,
            'error' => $code,
            'message' => $message,
            'details' => $details ?? [],
            'timestamp' => date('c'),
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/',
            'requestId' => self::requestId(),
        ], $status);
    }

    /** Identificador único da requisição (rastreabilidade/correlação de logs). */
    public static function requestId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        }
        return $id;
    }

    private static function send(array $payload, int $status): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
