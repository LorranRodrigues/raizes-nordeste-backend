<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Regiao;
use App\Models\Unidade;

/**
 * Cadastro de unidades (franquias) — gerido pela MATRIZ.
 */
final class UnidadeController extends Controller
{
    public function index(Request $request): void
    {
        Response::success((new Unidade())->comRegiao());
    }

    public function show(Request $request): void
    {
        Response::success((new Unidade())->findOrFail((int) $request->param('id')));
    }

    public function store(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $dados = Validator::make($request->all(), [
            'regiao_id' => 'required|integer',
            'nome' => 'required|string|max:120',
            'tipo' => 'required|in:COMPLETA,REDUZIDA',
            'cidade' => 'required|string|max:80',
            'estado' => 'required|string|min:2|max:2',
            'endereco' => 'string|max:200',
            'telefone' => 'string|max:20',
        ]);

        // Garante integridade referencial com mensagem amigável.
        (new Regiao())->findOrFail($dados['regiao_id']);

        $model = new Unidade();
        $id = $model->create($dados);
        $this->audit($request, 'CRIAR', 'unidades', $id, 'Unidade criada', null, $dados);

        Response::success($model->find($id), 201);
    }

    public function update(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Unidade();
        $atual = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'regiao_id' => 'integer',
            'nome' => 'string|max:120',
            'tipo' => 'in:COMPLETA,REDUZIDA',
            'cidade' => 'string|max:80',
            'estado' => 'string|min:2|max:2',
            'endereco' => 'string|max:200',
            'telefone' => 'string|max:20',
            'ativa' => 'boolean',
        ]);
        if ($dados === []) {
            throw HttpException::unprocessable('Nenhum campo para atualizar.');
        }
        if (isset($dados['regiao_id'])) {
            (new Regiao())->findOrFail($dados['regiao_id']);
        }

        $model->update($atual['id'], $dados);
        $this->audit($request, 'ATUALIZAR', 'unidades', $atual['id'], 'Unidade atualizada', $atual, $dados);

        Response::success($model->find($atual['id']));
    }

    public function destroy(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Unidade();
        $atual = $model->findOrFail((int) $request->param('id'));

        // Desativação lógica preserva histórico de pedidos (rastreabilidade).
        $model->update($atual['id'], ['ativa' => 0]);
        $this->audit($request, 'DESATIVAR', 'unidades', $atual['id'], 'Unidade desativada', $atual, ['ativa' => 0]);

        Response::success(['desativada' => true]);
    }
}
