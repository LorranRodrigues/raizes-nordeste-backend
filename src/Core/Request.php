<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Abstrai a requisição HTTP: parâmetros de rota, query string, corpo JSON
 * e o usuário autenticado (preenchido pelo middleware de autenticação).
 *
 * Será enriquecida nas próximas tasks (validação, auth). Por ora cobre o
 * essencial para o roteamento funcionar.
 */
final class Request
{
    /** @var array<string,string> Parâmetros da rota (ex.: {id}). */
    private array $params;

    /** @var array<string,mixed> Corpo decodificado (JSON ou form). */
    private array $body;

    /** @var array<string,mixed> Dados do funcionário autenticado. */
    private array $auth = [];

    public function __construct(array $params = [])
    {
        $this->params = $params;
        $this->body = $this->parseBody();
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return $_POST ?? [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string,mixed> Todo o corpo da requisição. */
    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if ($header && preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Define o funcionário autenticado (chamado pelo middleware de auth). */
    public function setAuth(array $auth): void
    {
        $this->auth = $auth;
    }

    public function auth(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->auth;
        }
        return $this->auth[$key] ?? null;
    }
}
