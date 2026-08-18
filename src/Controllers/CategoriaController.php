<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Categoria;

/**
 * Cadastro de categorias do cardápio — gerido pela MATRIZ.
 */
final class CategoriaController extends Controller
{
    public function index(Request $request): void
    {
        Response::success((new Categoria())->all([], 'nome ASC'));
    }

    public function store(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $dados = Validator::make($request->all(), [
            'nome' => 'required|string|max:80',
            'descricao' => 'string|max:200',
        ]);

        $model = new Categoria();
        if ($model->firstWhere(['nome' => $dados['nome']]) !== null) {
            throw HttpException::conflict('Categoria já existe.');
        }
        $id = $model->create($dados);
        $this->audit($request, 'CRIAR', 'categorias', $id, 'Categoria criada', null, $dados);

        Response::success($model->find($id), 201);
    }

    public function update(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Categoria();
        $atual = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'nome' => 'string|max:80',
            'descricao' => 'string|max:200',
            'ativa' => 'boolean',
        ]);
        if ($dados === []) {
            throw HttpException::unprocessable('Nenhum campo para atualizar.');
        }
        $model->update($atual['id'], $dados);
        $this->audit($request, 'ATUALIZAR', 'categorias', $atual['id'], 'Categoria atualizada', $atual, $dados);

        Response::success($model->find($atual['id']));
    }
}
