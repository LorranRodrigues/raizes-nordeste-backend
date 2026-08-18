<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Auditoria;
use App\Models\Relatorio;

/**
 * Relatórios e auditoria para a matriz (decisões estratégicas, transparência e
 * rastreabilidade exigidas pela franqueadora). Restrito ao papel MATRIZ.
 */
final class RelatorioController extends Controller
{
    private function filtros(Request $request): array
    {
        return [
            'data_inicio' => $request->query('data_inicio'),
            'data_fim' => $request->query('data_fim'),
        ];
    }

    /** Vendas por unidade e por região. */
    public function vendas(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $f = $this->filtros($request);
        $model = new Relatorio();
        Response::success([
            'por_unidade' => $model->vendasPorUnidade($f),
            'por_regiao' => $model->vendasPorRegiao($f),
            'periodo' => $f,
        ]);
    }

    /** Produtos mais consumidos. */
    public function produtosMaisVendidos(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $limite = (int) ($request->query('limite', 10));
        Response::success((new Relatorio())->produtosMaisVendidos($this->filtros($request), $limite));
    }

    /** Resumo financeiro consolidado. */
    public function financeiro(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        Response::success((new Relatorio())->financeiro($this->filtros($request)));
    }

    /** Consulta da trilha de auditoria (operações sensíveis). */
    public function auditoria(Request $request): void
    {
        $this->authorize($request, ['MATRIZ']);
        $filtros = [
            'entidade' => $request->query('entidade'),
            'acao' => $request->query('acao'),
            'funcionario_id' => $request->query('funcionario_id'),
        ];
        Response::success((new Auditoria())->listar($filtros));
    }
}
