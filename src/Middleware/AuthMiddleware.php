<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\Funcionario;
use App\Support\Token;

/**
 * Exige um token Bearer válido. Verifica a assinatura, recarrega o funcionário
 * do banco (para refletir bloqueios/desativações em tempo real) e injeta os
 * dados de autenticação no Request, disponíveis para controllers e auditoria.
 */
final class AuthMiddleware
{
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized('Token de acesso ausente.');
        }

        $claims = Token::verify($token);

        $funcionario = (new Funcionario())->find((int) ($claims['sub'] ?? 0));
        if ($funcionario === null || (int) $funcionario['ativo'] !== 1) {
            throw HttpException::unauthorized('Funcionário inválido ou inativo.');
        }

        $request->setAuth([
            'id' => (int) $funcionario['id'],
            'nome' => $funcionario['nome'],
            'papel' => $funcionario['papel'],
            'unidade_id' => $funcionario['unidade_id'] !== null ? (int) $funcionario['unidade_id'] : null,
        ]);
    }
}
