<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Funcionario;
use App\Support\Token;

/**
 * Autenticação de funcionários. Emite token de acesso após validar
 * credenciais. A senha é verificada contra um hash bcrypt (nunca em texto).
 */
final class AuthController extends Controller
{
    public function login(Request $request): void
    {
        $dados = Validator::make($request->all(), [
            'email' => 'required|email',
            'senha' => 'required|string|min:6',
        ]);

        $model = new Funcionario();
        $funcionario = $model->findByEmail($dados['email']);

        // Mensagem genérica para não revelar se o e-mail existe (segurança).
        if ($funcionario === null || !password_verify($dados['senha'], $funcionario['senha_hash'])) {
            throw HttpException::unauthorized('Credenciais inválidas.');
        }
        if ((int) $funcionario['ativo'] !== 1) {
            throw HttpException::forbidden('Conta desativada. Procure a matriz.');
        }

        $token = Token::issue([
            'sub' => (int) $funcionario['id'],
            'papel' => $funcionario['papel'],
            'unidade_id' => $funcionario['unidade_id'] !== null ? (int) $funcionario['unidade_id'] : null,
        ]);

        // Define o contexto autenticado e audita o acesso (rastreabilidade).
        $request->setAuth(['id' => (int) $funcionario['id'], 'papel' => $funcionario['papel']]);
        $this->audit($request, 'LOGIN', 'funcionarios', $funcionario['id'], 'Login realizado.');

        Response::success([
            'token' => $token,
            'tipo' => 'Bearer',
            'funcionario' => Funcionario::publico($funcionario),
        ]);
    }

    /** Retorna o funcionário autenticado (rota protegida). */
    public function me(Request $request): void
    {
        Response::success($request->auth());
    }
}
