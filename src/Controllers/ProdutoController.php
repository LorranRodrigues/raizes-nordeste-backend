<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Categoria;
use App\Models\Produto;

/**
 * Catálogo global de produtos da franqueadora — gerido pela MATRIZ.
 * O preço/disponibilidade por loja fica no cardápio da unidade (Task 6).
 */
final class ProdutoController extends Controller
{
    public function index(Request $request): void
    {
        $where = [];
        if ($request->query('categoria_id') !== null) {
            $where['categoria_id'] = (int) $request->query('categoria_id');
        }
        $p = \App\Support\Pagination::params($request);
        $resultado = (new Produto())->comCategoria($where, $p['page'], $p['limit']);

        Response::success(
            $resultado['data'],
            200,
            \App\Support\Pagination::meta($resultado['total'], $p['page'], $p['limit'])
        );
    }

    public function show(Request $request): void
    {
        Response::success((new Produto())->findOrFail((int) $request->param('id')));
    }

    public function store(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $dados = Validator::make($request->all(), [
            'categoria_id' => 'required|integer',
            'nome' => 'required|string|max:120',
            'descricao' => 'string|max:255',
            'preco_base' => 'required|numeric|min:0',
            'sazonal' => 'boolean',
        ]);

        (new Categoria())->findOrFail($dados['categoria_id']);

        $model = new Produto();
        $id = $model->create($dados);
        $this->audit($request, 'CRIAR', 'produtos', $id, 'Produto criado no catálogo', null, $dados);

        Response::success($model->find($id), 201);
    }

    public function update(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Produto();
        $atual = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'categoria_id' => 'integer',
            'nome' => 'string|max:120',
            'descricao' => 'string|max:255',
            'preco_base' => 'numeric|min:0',
            'sazonal' => 'boolean',
            'ativo' => 'boolean',
        ]);
        if ($dados === []) {
            throw HttpException::unprocessable('Nenhum campo para atualizar.');
        }
        if (isset($dados['categoria_id'])) {
            (new Categoria())->findOrFail($dados['categoria_id']);
        }

        $model->update($atual['id'], $dados);
        $this->audit($request, 'ATUALIZAR', 'produtos', $atual['id'], 'Produto atualizado', $atual, $dados);

        Response::success($model->find($atual['id']));
    }

    public function destroy(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $model = new Produto();
        $atual = $model->findOrFail((int) $request->param('id'));

        // Inativação lógica (produto pode estar referenciado em pedidos antigos).
        $model->update($atual['id'], ['ativo' => 0]);
        $this->audit($request, 'INATIVAR', 'produtos', $atual['id'], 'Produto inativado', $atual, ['ativo' => 0]);

        Response::success(['inativado' => true]);
    }
}
