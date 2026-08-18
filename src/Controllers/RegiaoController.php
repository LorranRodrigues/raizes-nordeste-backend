<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Regiao;

/**
 * Cadastro de regiões — gerido pela MATRIZ (padronização da franquia).
 */
final class RegiaoController extends Controller
{
    public function index(Request $request): void
    {
        Response::success((new Regiao())->all([], 'nome ASC'));
    }

    public function show(Request $request): void
    {
        Response::success((new Regiao())->findOrFail((int) $request->param('id')));
    }

    public function store(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $dados = Validator::make($request->all(), ['nome' => 'required|string|max:80']);

        $model = new Regiao();
        if ($model->firstWhere(['nome' => $dados['nome']]) !== null) {
            throw HttpException::conflict('Já existe uma região com este nome.');
        }
        $id = $model->create($dados);
        $this->audit($request, 'CRIAR', 'regioes', $id, 'Região criada', null, $dados);

        Response::success($model->find($id), 201);
    }

    public function update(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Regiao();
        $atual = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), ['nome' => 'required|string|max:80']);
        $model->update($atual['id'], $dados);
        $this->audit($request, 'ATUALIZAR', 'regioes', $atual['id'], 'Região atualizada', $atual, $dados);

        Response::success($model->find($atual['id']));
    }

    public function destroy(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Regiao();
        $atual = $model->findOrFail((int) $request->param('id'));

        try {
            $model->delete($atual['id']);
        } catch (\PDOException $e) {
            throw HttpException::conflict('Região possui unidades vinculadas e não pode ser removida.');
        }
        $this->audit($request, 'EXCLUIR', 'regioes', $atual['id'], 'Região excluída', $atual, null);

        Response::success(['removido' => true]);
    }
}
