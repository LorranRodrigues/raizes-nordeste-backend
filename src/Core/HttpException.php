<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Exceção que carrega um código HTTP, um código de erro textual e detalhes.
 * Permite que qualquer camada (controller, middleware, serviço) interrompa o
 * fluxo de forma limpa, deixando o front controller traduzir para o JSON de
 * erro padronizado (error, message, details, timestamp, path, requestId).
 */
class HttpException extends \RuntimeException
{
    private int $statusCode;
    private string $errorCode;
    private ?array $details;

    public function __construct(string $message, int $statusCode = 400, ?array $details = null, ?string $errorCode = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->details = $details;
        $this->errorCode = $errorCode ?? self::codigoPadrao($statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** Código textual padrão a partir do status HTTP. */
    private static function codigoPadrao(int $status): string
    {
        return match ($status) {
            400 => 'REQUISICAO_INVALIDA',
            401 => 'NAO_AUTENTICADO',
            403 => 'ACESSO_NEGADO',
            404 => 'NAO_ENCONTRADO',
            409 => 'CONFLITO',
            422 => 'VALIDACAO',
            500 => 'ERRO_INTERNO',
            default => 'ERRO',
        };
    }

    // Atalhos para os erros mais comuns da API.
    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self($message, 404, null, 'NAO_ENCONTRADO');
    }

    public static function unauthorized(string $message = 'Não autenticado.'): self
    {
        return new self($message, 401, null, 'NAO_AUTENTICADO');
    }

    public static function forbidden(string $message = 'Acesso negado.'): self
    {
        return new self($message, 403, null, 'ACESSO_NEGADO');
    }

    public static function unprocessable(string $message, ?array $details = null, ?string $errorCode = null): self
    {
        return new self($message, 422, $details, $errorCode ?? 'VALIDACAO');
    }

    public static function conflict(string $message, ?string $errorCode = null): self
    {
        return new self($message, 409, null, $errorCode ?? 'CONFLITO');
    }
}
