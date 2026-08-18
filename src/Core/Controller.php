<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Auditoria;

/**
 * Controller base com utilitários compartilhados:
 *  - autorização por papel (RBAC);
 *  - escopo de unidade (gerente só enxerga a própria unidade);
 *  - atalho para registrar auditoria.
 */
abstract class Controller
{
    /**
     * Garante que o funcionário autenticado tem um dos papéis permitidos.
     * Lança 403 caso contrário.
     */
    protected function authorize(Request $request, array $papeisPermitidos): void
    {
        $papel = $request->auth('papel');
        if ($papel === null) {
            throw HttpException::unauthorized();
        }
        if (!in_array($papel, $papeisPermitidos, true)) {
            throw HttpException::forbidden(
                'Seu papel (' . $papel . ') não tem permissão para esta operação.'
            );
        }
    }

    /**
     * Resolve a unidade que o funcionário pode operar.
     * MATRIZ pode atuar sobre qualquer unidade (informada na requisição);
     * demais papéis ficam restritos à própria unidade.
     */
    protected function unidadeEscopo(Request $request, ?int $unidadeSolicitada = null): int
    {
        $papel = $request->auth('papel');
        $unidadeFuncionario = $request->auth('unidade_id');

        if ($papel === 'MATRIZ') {
            if ($unidadeSolicitada === null) {
                throw HttpException::unprocessable('Informe a unidade (unidade_id) para esta operação.');
            }
            return $unidadeSolicitada;
        }

        if ($unidadeFuncionario === null) {
            throw HttpException::forbidden('Funcionário sem unidade associada.');
        }

        // Impede que um funcionário opere unidade diferente da sua.
        if ($unidadeSolicitada !== null && $unidadeSolicitada !== $unidadeFuncionario) {
            throw HttpException::forbidden('Você só pode operar a sua própria unidade.');
        }

        return $unidadeFuncionario;
    }

    /** Atalho para registrar auditoria a partir do contexto da requisição. */
    protected function audit(
        Request $request,
        string $acao,
        string $entidade,
        int|string|null $entidadeId = null,
        ?string $descricao = null,
        ?array $anteriores = null,
        ?array $novos = null
    ): void {
        (new Auditoria())->registrar(
            $request->auth('id'),
            $acao,
            $entidade,
            $entidadeId,
            $descricao,
            $anteriores,
            $novos
        );
    }
}
