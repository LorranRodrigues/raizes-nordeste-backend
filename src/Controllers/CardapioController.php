<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Produto;
use App\Models\Unidade;
use App\Models\UnidadeProduto;

/**
 * Cardápio por unidade (override local do catálogo global).
 *
 * - GET público (do ponto de vista do cliente): só itens vendáveis.
 * - Gestão (incluir/atualizar/remover item, ajustar estoque): GERENTE da
 *   unidade ou MATRIZ.
 */
final class CardapioController extends Controller
{
    /** Cardápio gerencial (todos os itens vinculados, com estoque). */
    public function index(Request $request): void
    {
        $unidadeId = (int) $request->param('unidadeId');
        (new Unidade())->findOrFail($unidadeId);
        Response::success((new UnidadeProduto())->cardapioDaUnidade($unidadeId));
    }

    /** Cardápio público para o cliente: só o que está disponível e com estoque. */
    public function publico(Request $request): void
    {
        $unidadeId = (int) $request->param('unidadeId');
        $unidade = (new Unidade())->findOrFail($unidadeId);
        if ((int) $unidade['ativa'] !== 1) {
            throw HttpException::notFound('Unidade indisponível no momento.');
        }
        Response::success([
            'unidade' => ['id' => $unidade['id'], 'nome' => $unidade['nome'], 'cidade' => $unidade['cidade']],
            'itens' => (new UnidadeProduto())->cardapioDaUnidade($unidadeId, true),
        ]);
    }

    /** Inclui (ou atualiza) um produto do catálogo no cardápio da unidade. */
    public function upsert(Request $request): void
    {
        $unidadeId = (int) $request->param('unidadeId');
        $this->authorize($request, ['GERENTE', 'MATRIZ']);
        $this->unidadeEscopo($request, $unidadeId); // gerente só na própria unidade
        (new Unidade())->findOrFail($unidadeId);

        $dados = Validator::make($request->all(), [
            'produto_id' => 'required|integer',
            'preco_local' => 'numeric|min:0',
            'disponivel' => 'boolean',
            'estoque' => 'integer|min:0',
        ]);
        (new Produto())->findOrFail($dados['produto_id']);

        $model = new UnidadeProduto();
        $existente = $model->doVinculo($unidadeId, (int) $dados['produto_id']);

        $payload = [
            'unidade_id' => $unidadeId,
            'produto_id' => (int) $dados['produto_id'],
            'preco_local' => $dados['preco_local'] ?? ($existente['preco_local'] ?? null),
            'disponivel' => $dados['disponivel'] ?? ($existente['disponivel'] ?? 1),
            'estoque' => $dados['estoque'] ?? ($existente['estoque'] ?? 0),
        ];

        if ($existente === null) {
            $model->create($payload);
            $acao = 'CARDAPIO_INCLUIR';
        } else {
            $model->update((int) $existente['id'], $payload);
            $acao = 'CARDAPIO_ATUALIZAR';
        }
        $this->audit($request, $acao, 'unidade_produtos', $unidadeId . ':' . $dados['produto_id'], null, $existente, $payload);

        Response::success($model->doVinculo($unidadeId, (int) $dados['produto_id']), $existente === null ? 201 : 200);
    }

    /** Ajuste pontual de estoque (entrada/saída manual) — operação auditada. */
    public function ajustarEstoque(Request $request): void
    {
        $unidadeId = (int) $request->param('unidadeId');
        $produtoId = (int) $request->param('produtoId');
        $this->authorize($request, ['GERENTE', 'MATRIZ']);
        $this->unidadeEscopo($request, $unidadeId);

        $dados = Validator::make($request->all(), ['estoque' => 'required|integer|min:0']);

        $model = new UnidadeProduto();
        $atual = $model->doVinculo($unidadeId, $produtoId);
        if ($atual === null) {
            throw HttpException::notFound('Produto não está no cardápio desta unidade.');
        }

        $model->update((int) $atual['id'], ['estoque' => (int) $dados['estoque']]);
        $this->audit(
            $request,
            'ESTOQUE_AJUSTE',
            'unidade_produtos',
            $unidadeId . ':' . $produtoId,
            'Ajuste manual de estoque',
            ['estoque' => $atual['estoque']],
            ['estoque' => (int) $dados['estoque']]
        );

        Response::success($model->doVinculo($unidadeId, $produtoId));
    }

    /** Remove um item do cardápio da unidade. */
    public function destroy(Request $request): void
    {
        $unidadeId = (int) $request->param('unidadeId');
        $produtoId = (int) $request->param('produtoId');
        $this->authorize($request, ['GERENTE', 'MATRIZ']);
        $this->unidadeEscopo($request, $unidadeId);

        $model = new UnidadeProduto();
        $atual = $model->doVinculo($unidadeId, $produtoId);
        if ($atual === null) {
            throw HttpException::notFound('Produto não está no cardápio desta unidade.');
        }
        $model->delete((int) $atual['id']);
        $this->audit($request, 'CARDAPIO_REMOVER', 'unidade_produtos', $unidadeId . ':' . $produtoId, null, $atual, null);

        Response::success(['removido' => true]);
    }
}
